<?php
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ilPluginExAssignmentStatusFile extends ilExcel
{
    const FORMAT_CSV = "Csv";

    protected $assignment;
    protected string $format;
    protected $members = [];
    protected $teams = [];
    protected $member_titles =  ['update','usr_id','login','lastname','firstname','status','mark','notice','comment', 'plagiarism', 'plag_comment'];
    protected $team_titles =  ['update','team_id','logins','status','mark','notice','comment', 'plagiarism', 'plag_comment'];
    protected $valid_states = ['notgraded','passed','failed'];
    protected $valid_plag_flags = ['none','suspicion','detected'];
    public $updates = [];
    protected $updates_applied = false;
    protected $error;
    protected $allow_plag_update = false;
    protected bool $loadfromfile_success = false;
    protected bool $writetofile_success = false;

    public function init(ilExAssignment $assignment) {
        $this->members = [];
        $this->teams = [];
        $this->updates = [];
        $this->updates_applied = false;
        $this->error = null;
        $this->assignment = $assignment;

        $this->initMembers();
        if ($this->assignment->getAssignmentType()->usesTeams()) {
            $this->initTeams();
        }
    }

    public function allowPlagiarismUpdate($allow = true) {
        $this->allow_plag_update = (bool) $allow;
    }

    public function getValidFormats(): array {
        return array(self::FORMAT_XML, self::FORMAT_BIFF, self::FORMAT_CSV);
    }

    public function getFilename() {
        switch($this->format) {
            case self::FORMAT_XML:
                return "status.xlsx";
            case self::FORMAT_BIFF:
                return "status.xls";
            case self::FORMAT_CSV:
            default:
                return "status.csv";
        }
    }

    public function loadFromFile(string $filename): void {
        $this->error = false;
        $this->updates = [];
        try {
            if (file_exists($filename)) {
                $this->workbook = IOFactory::load($filename);
                if ($this->assignment->getAssignmentType()->usesTeams()) {
                    $this->loadTeamSheet();
                }
                else {
                    $this->loadMemberSheet();
                }
                $this->loadfromfile_success = true;
                return;
            }
            $this->loadfromfile_success = false;
            return;
        }
        catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->loadfromfile_success = false;
            return;
        }
    }

    public function isWriteToFileSuccess(): bool {
        return $this->writetofile_success;
    }

    public function isLoadFromFileSuccess(): bool {
        return $this->loadfromfile_success;
    }

    public function writeToFile($a_file): void {
        try {
            if (!$this->workbook) {
                $this->workbook = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            }
            
            while ($this->workbook->getSheetCount() > 0) {
                $this->workbook->removeSheetByIndex(0);
            }
            
            if ($this->assignment->getAssignmentType()->usesTeams()) {
                $this->writeTeamSheet();
            } else {
                $this->writeMemberSheet();
            }
            
            $writer = IOFactory::createWriter($this->workbook, $this->format);
            $writer->save($a_file);
            
            $this->writetofile_success = true;
            
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->writetofile_success = false;
            throw $e;
        }
    }

    protected function prepareString($a_value): string
    {
        return $a_value;
    }

    protected function initMembers() {
        global $DIC;
        $db = $DIC->database();

        try {
            $exercise_obj_id = $this->assignment->getExerciseId();
            $assignment_id = $this->assignment->getId();

            $member_query = "SELECT usr_id FROM exc_members WHERE obj_id = " . $db->quote($exercise_obj_id, 'integer');
            $member_result = $db->query($member_query);

            $all_member_ids = [];
            while ($row = $db->fetchAssoc($member_result)) {
                $all_member_ids[] = (int)$row['usr_id'];
            }

            $this->members = $all_member_ids;
            $member_ids = $all_member_ids;

            if (empty($member_ids)) {
                $this->members = [];
                return;
            }
            
            $user_ids_string = implode(',', array_map('intval', $member_ids));
            
            $user_query = "SELECT usr_id, login, firstname, lastname FROM usr_data 
                           WHERE usr_id IN ($user_ids_string) AND active = 1";
            
            $user_result = $db->query($user_query);
            $users = [];
            
            while ($row = $db->fetchAssoc($user_result)) {
                $users[(int)$row['usr_id']] = $row;
            }
            
            $status_query = "SELECT usr_id, status, mark, notice, u_comment 
                             FROM exc_mem_ass_status 
                             WHERE ass_id = " . $db->quote($assignment_id, 'integer') . "
                             AND usr_id IN ($user_ids_string)";
            
            $status_result = $db->query($status_query);
            $statuses = [];
            
            while ($row = $db->fetchAssoc($status_result)) {
                $statuses[(int)$row['usr_id']] = $row;
            }
            
            $this->members = [];

            foreach ($member_ids as $user_id) {
                $user_data = $users[$user_id] ?? null;
                $status_data = $statuses[$user_id] ?? null;

                if (!$user_data) {
                    continue;
                }
                
                $status_string = 'notgraded';
                if ($status_data && !empty($status_data['status'])) {
                    switch ($status_data['status']) {
                        case 'passed':
                            $status_string = 'passed';
                            break;
                        case 'failed':
                            $status_string = 'failed';
                            break;
                        default:
                            $status_string = 'notgraded';
                            break;
                    }
                }
                
                $this->members[$user_id] = [
                    'usr_id' => $user_id,
                    'login' => $user_data['login'] ?? '',
                    'lastname' => $user_data['lastname'] ?? '',
                    'firstname' => $user_data['firstname'] ?? '',
                    'status' => $status_string,
                    'mark' => $status_data['mark'] ?? '',
                    'notice' => $status_data['notice'] ?? '',
                    'comment' => $status_data['comment'] ?? '',
                    'plag_flag' => 'none',
                    'plag_comment' => ''
                ];
            }

        } catch (Exception $e) {
            $this->members = [];
        }
    }

    protected function loadMemberSheet() {
        $sheet = $this->getSheetAsArray();

        $titles = array_shift($sheet);
        if (count(array_diff($this->member_titles, (array) $titles)) > 0) {
            throw new ilExerciseException("Status file has wrong column titles");
        }

        $index = array_flip($this->member_titles);

        // Filter empty rows before processing
        // PhpSpreadsheet may include empty rows at the end when CSV ends with newline
        $sheet = array_filter($sheet, function($row) {
            foreach ($row as $value) {
                if ($value !== null && $value !== '') {
                    return true;
                }
            }
            return false;
        });

        foreach ($sheet as $rowdata) {
            $data = [];

            // Robust update flag parsing - handle various input types
            $raw_update = $rowdata[$index['update']] ?? null;
            if ($raw_update === null || $raw_update === '') {
                $data['update'] = false;
            } elseif (is_numeric($raw_update)) {
                $data['update'] = (int)$raw_update !== 0;
            } else {
                $data['update'] = filter_var($raw_update, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            }

            $data['login'] = (string) ($rowdata[$index['login']] ?? '');
            $data['usr_id'] = (int) ($rowdata[$index['usr_id']] ?? 0);
            $data['status'] = (string) ($rowdata[$index['status']] ?? '');
            $data['mark'] = (string) ($rowdata[$index['mark']] ?? '');
            $data['notice'] = (string) ($rowdata[$index['notice']] ?? '');
            $data['comment'] = (string) ($rowdata[$index['comment']] ?? '');
            $data['plag_flag'] = ((string) ($rowdata[$index['plagiarism']] ?? '') ?: 'none');
            $data['plag_comment'] = (string) ($rowdata[$index['plag_comment']] ?? '');

            // Skip rows without valid user ID
            if ($data['usr_id'] === 0) {
                continue;
            }

            if (!$data['update']) {
                continue;
            }

            if (!isset($this->members[$data['usr_id']])) {
                continue;
            }

            // Status normalisieren
            $data['status'] = $this->normalizeStatus($data['status']);

            $this->checkRowData($data);
            $this->updates[] = $data;
        }
    }

    protected function writeTeamSheet() {
        $this->addSheet('teams');

        $col = 0;
        foreach ($this->team_titles as $title) {
            $this->setCell(1, $col++, $title);
        }

        $row = 2;
        foreach ($this->teams as $team_data) {
            $logins = [];
            $member = [];
            
            foreach ($team_data['members'] as $usr_id) {
                if (isset($this->members[$usr_id])) {
                    $logins[] = $this->members[$usr_id]['login'];
                    $member = $this->members[$usr_id];
                } else {
                    $logins[] = \ilObjUser::_lookupLogin($usr_id);
                }
            }

            if (empty($member)) {
                $member = [
                    'status' => $team_data['status'],
                    'mark' => $team_data['mark'],
                    'notice' => $team_data['notice'],
                    'comment' => $team_data['comment'],
                    'plag_flag' => $team_data['plag_flag'],
                    'plag_comment' => $team_data['plag_comment']
                ];
            }

            $col = 0;
            $this->setCell($row, $col++, 0, DataType::TYPE_NUMERIC);
            $this->setCell($row, $col++, $team_data['team_id'], DataType::TYPE_NUMERIC);
            $this->setCell($row, $col++, implode(', ', $logins), DataType::TYPE_STRING);
            $this->setCell($row, $col++, $member['status'], DataType::TYPE_STRING);
            $this->setCell($row, $col++, $member['mark'], DataType::TYPE_STRING);
            $this->setCell($row, $col++, $member['notice'], DataType::TYPE_STRING);
            $this->setCell($row, $col++, $member['comment'], DataType::TYPE_STRING);
            $this->setCell($row, $col++, ($member['plag_flag'] == 'none' ? '' : $member['plag_flag']), DataType::TYPE_STRING);
            $this->setCell($row, $col, $member['plag_comment'], DataType::TYPE_STRING);
            $row++;
        }
    }

    protected function loadTeamSheet() {
        $sheet = $this->getSheetAsArray();

        $titles = array_shift($sheet);
        if (count(array_diff($this->team_titles, (array) $titles)) > 0) {
            throw new ilExerciseException("Team status file has wrong column titles");
        }

        $index = array_flip($this->team_titles);

        // Filter empty rows before processing
        // PhpSpreadsheet may include empty rows at the end when CSV ends with newline
        $sheet = array_filter($sheet, function($row) {
            foreach ($row as $value) {
                if ($value !== null && $value !== '') {
                    return true;
                }
            }
            return false;
        });

        $skipped_rows = 0;

        foreach ($sheet as $rowdata) {
            $data = [];

            // Robust update flag parsing
            $raw_update = $rowdata[$index['update']] ?? null;
            if ($raw_update === null || $raw_update === '') {
                $data['update'] = false;
            } elseif (is_numeric($raw_update)) {
                $data['update'] = (int)$raw_update !== 0;
            } else {
                $data['update'] = filter_var($raw_update, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            }

            $data['team_id'] = (string) ($rowdata[$index['team_id']] ?? '');
            $data['status'] = (string) ($rowdata[$index['status']] ?? '');
            $data['mark'] = (string) ($rowdata[$index['mark']] ?? '');
            $data['notice'] = (string) ($rowdata[$index['notice']] ?? '');
            $data['comment'] = (string) ($rowdata[$index['comment']] ?? '');
            $data['plag_flag'] = ((string) ($rowdata[$index['plagiarism']] ?? '') ?: 'none');
            $data['plag_comment'] = (string) ($rowdata[$index['plag_comment']] ?? '');

            // Skip rows without valid team ID
            if ($data['team_id'] === '' || $data['team_id'] === '0') {
                continue;
            }

            if (!$data['update']) {
                $skipped_rows++;
                continue;
            }

            if (!isset($this->teams[$data['team_id']])) {
                $skipped_rows++;
                continue;
            }

            // Status normalisieren
            $data['status'] = $this->normalizeStatus($data['status']);

            $this->checkRowData($data);
            $this->updates[] = $data;
        }

        if (count($this->updates) === 0 && $skipped_rows > 0) {
            throw new ilExerciseException(
                "No valid updates found! Check that:\n" .
                "1. 'update' column is set to 1\n" .
                "2. Team IDs exist in the assignment\n" .
                "3. Status values are valid (passed/failed/notgraded)"
            );
        }
    }

    protected function checkRowData($data) {
        if (!in_array($data['status'], $this->valid_states)) {
            $allowed_examples = [
                'passed (or: bestanden, ok, success, 1, yes)',
                'failed (or: nicht bestanden, not passed, fail, 0, no)', 
                'notgraded (or: not graded, nicht bewertet, pending, offen)'
            ];
            
            throw new ilExerciseException(
                "Invalid status: '{$data['status']}'. Allowed:\n" . 
                "- " . implode("\n- ", $allowed_examples)
            );
        }
    }

    /**
     * Status-Werte normalisieren
     */
    protected function normalizeStatus(string $status): string {
        $status = trim(strtolower($status));
        
        $status_mapping = [
            // Passed Variationen
            'passed' => 'passed',
            'bestanden' => 'passed', 
            'ok' => 'passed',
            'success' => 'passed',
            '1' => 'passed',
            'yes' => 'passed',
            'ja' => 'passed',
            
            // Failed Variationen
            'failed' => 'failed',
            'not passed' => 'failed',
            'nicht bestanden' => 'failed',
            'fail' => 'failed',
            '0' => 'failed', 
            'no' => 'failed',
            'nein' => 'failed',
            
            // Not graded Variationen
            'notgraded' => 'notgraded',
            'not graded' => 'notgraded',
            'nicht bewertet' => 'notgraded',
            'pending' => 'notgraded',
            '' => 'notgraded'
        ];
        
        return $status_mapping[$status] ?? $status;
    }

    public function applyStatusUpdates() {
        foreach ($this->updates as $data) {
            $user_ids = [];
            if (isset($data['usr_id'])) {
                $user_ids = [$data['usr_id']];
            } elseif (isset($data['team_id'])) {
                if (isset($this->teams[$data['team_id']])) {
                    $team_data = $this->teams[$data['team_id']];
                    $user_ids = $team_data['members'];
                }
            }

            foreach ($user_ids as $user_id) {
                $status = new ilExAssignmentMemberStatus($this->assignment->getId(), $user_id);
                $status->setStatus($data['status']);
                $status->setMark($data['mark']);
                $status->setComment($data['comment']);
                $status->setNotice($data['notice']);

                if ($this->allow_plag_update) {
                    // TODO: Plagiarism flags when available
                }

                $status->update();
            }
        }

        $this->updates_applied = true;
    }

    public function hasError() {
        return !empty($this->error);
    }

    public function hasUpdates() {
        return !empty($this->updates);
    }

    public function getInfo() {
        if ($this->hasError()) {
            return "Status file error in " . $this->getFilename() . ": " . $this->error;
        }
        elseif (!$this->hasUpdates()) {
            return "No updates found in " . $this->getFilename();
        }
        else {
            $list = [];
            foreach ($this->updates as $data) {
                $list[] = (empty($this->teams) ? $data['login'] : 'Team ' . $data['team_id']);
            }

            if ($this->updates_applied) {
                $type = empty($this->teams) ? 'users' : 'teams';
                return "Status updates applied for $type: " . implode(', ', $list);
            }
            else {
                $type = empty($this->teams) ? 'users' : 'teams';
                return "Status updates found for $type in " . $this->getFilename() . ": " . implode(', ', $list);
            }
        }
    }

    public function removeMemberSheet(){
        if ($this->workbook && $this->workbook->getSheetByName('members')) {
            $this->workbook->removeSheetByIndex(
                $this->workbook->getIndex(
                    $this->workbook->getSheetByName('members')
                )
            );
        }
    }

    protected function writeMemberSheet() {
        try {
            if ($this->workbook->getSheetCount() == 0) {
                $sheet = $this->workbook->createSheet();
            } else {
                $sheet = $this->workbook->getActiveSheet();
            }
            
            $sheet->setTitle('members');
            
            $col = 1;
            foreach ($this->member_titles as $title) {
                $sheet->setCellValue([$col, 1], $title);
                $col++;
            }
            
            $row = 2;
            foreach ($this->members as $member) {
                $col = 1;
                
                $sheet->setCellValue([$col, $row], 0);
                $col++;
                
                $sheet->setCellValue([$col, $row], $member['usr_id']);
                $col++;
                
                $sheet->setCellValue([$col, $row], $member['login']);
                $col++;
                
                $sheet->setCellValue([$col, $row], $member['lastname']);
                $col++;
                
                $sheet->setCellValue([$col, $row], $member['firstname']);
                $col++;
                
                $sheet->setCellValue([$col, $row], $member['status']);
                $col++;
                
                $sheet->setCellValue([$col, $row], $member['mark']);
                $col++;
                
                $sheet->setCellValue([$col, $row], $member['notice']);
                $col++;
                
                $sheet->setCellValue([$col, $row], $member['comment']);
                $col++;
                
                $plag_display = ($member['plag_flag'] == 'none' ? '' : $member['plag_flag']);
                $sheet->setCellValue([$col, $row], $plag_display);
                $col++;
                
                $sheet->setCellValue([$col, $row], $member['plag_comment']);
                
                $row++;
            }
            
        } catch (Exception $e) {
            throw $e;
        }
    }   

    protected function initTeams() {
        global $DIC;
        $db = $DIC->database();
        
        try {
            $assignment_id = $this->assignment->getId();
            
            $teams = ilExAssignmentTeam::getInstancesFromMap($assignment_id);
            
            if (empty($teams)) {
                $this->teams = [];
                return;
            }
            
            $this->teams = [];
            
            foreach ($teams as $team_id => $team) {
                $member_logins = [];
                $member_names = [];
                $member_ids = $team->getMembers();
                
                foreach ($member_ids as $member_id) {
                    $user_data = \ilObjUser::_lookupName($member_id);
                    if ($user_data) {
                        $member_logins[] = $user_data['login'];
                        $member_names[] = $user_data['firstname'] . ' ' . $user_data['lastname'];
                    }
                }
                
                $first_member = reset($member_ids);
                $member_status = $this->assignment->getMemberStatus($first_member);
                
                $status_string = 'notgraded';
                if ($member_status) {
                    $status_obj = $member_status->getStatus();
                    if ($status_obj == 'passed') {
                        $status_string = 'passed';
                    } elseif ($status_obj == 'failed') {
                        $status_string = 'failed';
                    }
                }
                
                $this->teams[$team_id] = [
                    'team_id' => $team_id,
                    'team_object' => $team,
                    'members' => $member_ids,
                    'logins' => implode(', ', $member_logins),
                    'member_names' => implode(', ', $member_names),
                    'status' => $status_string,
                    'mark' => $member_status ? $member_status->getMark() : '',
                    'notice' => $member_status ? $member_status->getNotice() : '',
                    'comment' => $member_status ? $member_status->getComment() : '',
                    'plag_flag' => 'none',
                    'plag_comment' => ''
                ];
            }
            
        } catch (Exception $e) {
            $this->teams = [];
        }
    }

    public function getUpdates(): array
    {
        return $this->updates;
    }
}
?>