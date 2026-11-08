<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Patient app: appointment booking by slot, and live queue position.
 *
 * Slots follow the web (Customlib::getSlotByDoctorShift): a doctor's shift for that weekday
 * (doctor_shift_time) split into steps of shift_details.consult_duration minutes.
 * App bookings are saved as source 'Mobile App', status 'pending' (the hospital approves; the fee is
 * paid at the counter). They are NOT tagged 'Online', because Cron::releaseAppointment deletes unpaid
 * 'online' bookings after 10 minutes.
 */
class Mobilebooking_model extends CI_Model
{
    const SOURCE           = 'Mobile App';
    const BOOK_AHEAD_DAYS  = 60;
    const DEFAULT_DURATION = 10; // minutes, when a doctor has no consult duration set

    public function doctors()
    {
        $rows = $this->db->query("SELECT s.id, s.name, s.surname, s.employee_id, s.qualification, s.specialist, s.image, d.department_name department,
                sd.consult_duration, ch.standard_charge fee
            FROM staff s
            JOIN staff_roles sr ON sr.staff_id = s.id AND sr.role_id = 3
            LEFT JOIN department d ON d.id = s.department_id
            LEFT JOIN shift_details sd ON sd.staff_id = s.id
            LEFT JOIN charges ch ON ch.id = sd.charge_id
            WHERE s.is_active = 1 AND EXISTS (SELECT 1 FROM doctor_shift_time t WHERE t.staff_id = s.id)
            ORDER BY s.name, s.surname")->result_array();
        $spec = array();
        foreach ($this->db->query("SELECT id, specialist_name FROM specialist")->result_array() as $r) {
            $spec[(int) $r['id']] = $r['specialist_name'];
        }
        $days = array();
        foreach ($this->db->query("SELECT DISTINCT staff_id, day FROM doctor_shift_time")->result_array() as $r) {
            $days[(int) $r['staff_id']][] = $r['day'];
        }
        $out = array();
        foreach ($rows as $r) {
            $ids = array_filter(array_map('intval', explode(',', (string) $r['specialist'])));
            $out[] = array(
                'id'               => (int) $r['id'],
                'name'             => trim('Dr. ' . $r['name'] . ' ' . $r['surname']),
                'qualification'    => $r['qualification'],
                'department'       => $r['department'],
                'specialties'      => array_values(array_filter(array_map(function ($i) use ($spec) { return isset($spec[$i]) ? $spec[$i] : null; }, $ids))),
                'fee'              => $r['fee'] !== null ? (float) $r['fee'] : null,
                'consult_minutes'  => (int) $r['consult_duration'] ?: self::DEFAULT_DURATION,
                'days'             => isset($days[(int) $r['id']]) ? array_values(array_unique($days[(int) $r['id']])) : array(),
                'image'            => !empty($r['image']) ? base_url('uploads/staff_images/' . $r['image']) : null,
            );
        }
        return $out;
    }

    private function duration($doctor_id)
    {
        $r = $this->db->query("SELECT consult_duration FROM shift_details WHERE staff_id = ?", array((int) $doctor_id))->row_array();
        $d = $r ? (int) $r['consult_duration'] : 0;
        return $d > 0 ? $d : self::DEFAULT_DURATION;
    }

    /** The doctor's shifts on a date, each with its slots and whether they are free. */
    public function slots($doctor_id, $date)
    {
        $day   = date('l', strtotime($date));
        $shifts = $this->db->query("SELECT t.id, t.start_time, t.end_time, dg.global_shift_id, g.name
            FROM doctor_shift_time t JOIN doctor_global_shift dg ON dg.id = t.doctor_global_shift_id LEFT JOIN global_shift g ON g.id = dg.global_shift_id
            WHERE t.staff_id = ? AND t.day = ? ORDER BY t.start_time", array((int) $doctor_id, $day))->result_array();
        $taken = array();
        foreach ($this->db->query("SELECT date FROM appointment WHERE doctor = ? AND DATE(date) = ? AND appointment_status IN ('pending', 'approved')",
            array((int) $doctor_id, $date))->result_array() as $a) {
            $taken[date('H:i', strtotime($a['date']))] = true;
        }
        $step = $this->duration($doctor_id) * 60;
        $now  = time();
        $out  = array();
        foreach ($shifts as $s) {
            $slots = array();
            for ($t = strtotime($date . ' ' . $s['start_time']), $end = strtotime($date . ' ' . $s['end_time']); $t < $end; $t += $step) {
                $hm = date('H:i', $t);
                $slots[] = array('time' => $hm, 'available' => !isset($taken[$hm]) && $t > $now + 300);
            }
            $out[] = array('shift_id' => (int) $s['id'], 'global_shift_id' => (int) $s['global_shift_id'], 'name' => $s['name'] ?: 'Shift',
                'start' => substr($s['start_time'], 0, 5), 'end' => substr($s['end_time'], 0, 5), 'slots' => $slots);
        }
        return $out;
    }

    /** Book a slot. Returns array('appointment' => row) or array('error' => message). */
    public function book($patient_id, $doctor_id, $shift_id, $date, $time, $message)
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            return array('error' => 'Choose a date and time.');
        }
        $when = strtotime("$date $time");
        if ($when < time() + 300 || $when > strtotime('+' . self::BOOK_AHEAD_DAYS . ' days')) {
            return array('error' => 'That time can no longer be booked. Pick another slot.');
        }
        $shift = $this->db->query("SELECT t.id, t.day, t.start_time, t.end_time, t.doctor_global_shift_id FROM doctor_shift_time t
            WHERE t.id = ? AND t.staff_id = ?", array((int) $shift_id, (int) $doctor_id))->row_array();
        if (!$shift || $shift['day'] !== date('l', $when)) {
            return array('error' => 'The doctor is not available at that time.');
        }
        // The time must be one of the generated slots.
        $step = $this->duration($doctor_id) * 60;
        $ok = false;
        for ($t = strtotime($date . ' ' . $shift['start_time']), $end = strtotime($date . ' ' . $shift['end_time']); $t < $end; $t += $step) {
            if (date('H:i', $t) === $time) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return array('error' => 'That time is not a bookable slot.');
        }
        if ($this->db->query("SELECT 1 FROM appointment WHERE patient_id = ? AND doctor = ? AND DATE(date) = ? AND appointment_status IN ('pending', 'approved') LIMIT 1",
            array((int) $patient_id, (int) $doctor_id, $date))->row_array()) {
            return array('error' => 'You already have an appointment with this doctor on that day.');
        }

        $lock = 'appt_' . (int) $doctor_id . '_' . $date;
        $this->db->query("SELECT GET_LOCK(?, 5)", array($lock));
        try {
            $datetime = "$date $time:00";
            if ($this->db->query("SELECT 1 FROM appointment WHERE doctor = ? AND date = ? AND appointment_status IN ('pending', 'approved') LIMIT 1",
                array((int) $doctor_id, $datetime))->row_array()) {
                return array('error' => 'Someone just booked that slot. Please pick another.');
            }
            $doc  = $this->db->query("SELECT s.specialist, ch.standard_charge FROM staff s LEFT JOIN shift_details sd ON sd.staff_id = s.id LEFT JOIN charges ch ON ch.id = sd.charge_id WHERE s.id = ?",
                array((int) $doctor_id))->row_array();
            $spec = $doc ? (int) current(array_filter(array_map('intval', explode(',', (string) $doc['specialist'])))) : 0;
            $prio = $this->db->query("SELECT id FROM appoint_priority ORDER BY id LIMIT 1")->row_array();
            $now  = date('Y-m-d H:i:s');
            $this->db->insert('appointment', array(
                'patient_id'             => (int) $patient_id,
                'date'                   => $datetime,
                'priority'               => $prio ? $prio['id'] : '',
                'specialist'             => $spec ?: '',
                'doctor'                 => (int) $doctor_id,
                'amount'                 => $doc && $doc['standard_charge'] !== null ? (float) $doc['standard_charge'] : 0,
                'message'                => mb_substr(strip_tags((string) $message), 0, 500),
                'appointment_status'     => 'pending',
                'source'                 => self::SOURCE,
                'is_opd'                 => 'no',
                'is_ipd'                 => 'no',
                'doctor_shift_time_id'   => (int) $shift_id,
                'doctor_global_shift_id' => (int) $shift['doctor_global_shift_id'],
                'is_queue'               => 0,
                'created_time'           => $now,
                'rejected_time'          => $now,
                'live_consult'           => 'no',
                'live_consult_link'      => 0,
            ));
            $id = (int) $this->db->insert_id();
        } finally {
            $this->db->query("SELECT RELEASE_LOCK(?)", array($lock));
        }
        return $id ? array('appointment_id' => $id) : array('error' => 'Could not book; please try again.');
    }

    public function cancel($patient_id, $appointment_id)
    {
        $a = $this->db->query("SELECT id, date, appointment_status, visit_details_id FROM appointment WHERE id = ? AND patient_id = ?",
            array((int) $appointment_id, (int) $patient_id))->row_array();
        if (!$a) {
            return 'Appointment not found.';
        }
        if (!in_array($a['appointment_status'], array('pending', 'approved'), true) || !empty($a['visit_details_id']) || strtotime($a['date']) < time()) {
            return 'This appointment can no longer be cancelled from the app. Please call the hospital.';
        }
        $this->db->query("UPDATE appointment SET appointment_status = 'cancel' WHERE id = ?", array((int) $appointment_id));
        return null;
    }

    /**
     * Queue position for the patient's appointments today: token (if the hospital has queued it),
     * how many patients are ahead, who is with the doctor now, and an estimated wait.
     */
    public function queue($patient_id)
    {
        $mine = $this->db->query("SELECT a.id, a.doctor, a.date, a.appointment_status status, a.doctor_shift_time_id, a.visit_details_id,
                (SELECT q.position FROM appointment_queue q WHERE q.appointment_id = a.id ORDER BY q.id DESC LIMIT 1) token,
                TRIM(CONCAT('Dr. ', s.name, ' ', s.surname)) doctor_name
            FROM appointment a LEFT JOIN staff s ON s.id = a.doctor
            WHERE a.patient_id = ? AND DATE(a.date) = CURDATE() AND a.appointment_status IN ('pending', 'approved') ORDER BY a.date",
            array((int) $patient_id))->result_array();
        $out = array();
        foreach ($mine as $m) {
            $doctor = (int) $m['doctor'];
            // Everyone booked with this doctor today, in queue order (token, else slot time).
            $all = $this->db->query("SELECT a.id, a.date, a.visit_details_id,
                    (SELECT q.position FROM appointment_queue q WHERE q.appointment_id = a.id ORDER BY q.id DESC LIMIT 1) token
                FROM appointment a WHERE a.doctor = ? AND DATE(a.date) = CURDATE() AND a.appointment_status IN ('pending', 'approved')
                ORDER BY (token IS NULL), token, a.date", array($doctor))->result_array();
            $done    = $this->finished_visits(array_filter(array_column($all, 'visit_details_id')));
            $serving = $this->with_doctor($doctor);
            $ahead   = 0;
            foreach ($all as $a) {
                if ((int) $a['id'] === (int) $m['id']) {
                    break;
                }
                if (empty($a['visit_details_id']) || !isset($done[(int) $a['visit_details_id']])) {
                    $ahead++;
                }
            }
            $mine_done = !empty($m['visit_details_id']) && isset($done[(int) $m['visit_details_id']]);
            $out[] = array(
                'appointment_id'  => (int) $m['id'],
                'doctor_name'     => $m['doctor_name'],
                'time'            => $m['date'],
                'status'          => $m['status'],
                'token'           => $m['token'] !== null ? (int) $m['token'] : null,
                'checked_in'      => !empty($m['visit_details_id']),
                'seen'            => $mine_done,
                'ahead'           => $mine_done ? 0 : $ahead,
                'now_serving'     => $serving,
                'wait_minutes'    => $mine_done ? 0 : $ahead * $this->duration($doctor),
            );
        }
        return $out;
    }

    /** Visits that are finished: a prescription was written, or the doctor marked them seen/skipped in the app. */
    private function finished_visits(array $visit_ids)
    {
        $visit_ids = array_values(array_unique(array_map('intval', $visit_ids)));
        if (empty($visit_ids)) {
            return array();
        }
        $in   = implode(',', $visit_ids);
        $done = array();
        foreach ($this->db->query("SELECT DISTINCT visit_details_id v FROM ipd_prescription_basic WHERE visit_details_id IN ($in)")->result_array() as $r) {
            $done[(int) $r['v']] = true;
        }
        foreach ($this->db->query("SELECT data FROM clinical_records WHERE record_type = 'opd_status' AND deleted = 0 AND event_date = CURDATE()")->result_array() as $r) {
            $d = json_decode($r['data'], true);
            if (isset($d['visit_id'], $d['state']) && in_array((int) $d['visit_id'], $visit_ids, true) && in_array($d['state'], array('seen', 'skipped'), true)) {
                $done[(int) $d['visit_id']] = true;
            }
        }
        return $done;
    }

    /** Token (or time) of the patient the doctor is seeing now, from the doctor app's queue. */
    private function with_doctor($doctor_id)
    {
        foreach ($this->db->query("SELECT r.data FROM clinical_records r JOIN visit_details vd ON vd.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(r.data, '$.visit_id')) AS UNSIGNED)
                WHERE r.record_type = 'opd_status' AND r.deleted = 0 AND r.event_date = CURDATE() AND vd.cons_doctor = ? ORDER BY r.updated_at DESC",
            array((int) $doctor_id))->result_array() as $r) {
            $d = json_decode($r['data'], true);
            if (isset($d['state']) && $d['state'] === 'with_doctor') {
                return isset($d['token']) && $d['token'] !== null ? array('token' => (int) $d['token']) : array('token' => null);
            }
        }
        return null;
    }
}
