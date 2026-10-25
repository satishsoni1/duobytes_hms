<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Builds print header images (uploads/printing/<id>.jpg) from the hospital
 * name, address, phone and email in General Settings, in the same 2230x300
 * layout as the stock headers: hospital name on the left, contact details on
 * the right, and a bar with the document title along the bottom.
 */
class Print_header_generator
{
    const WIDTH  = 2230;
    const HEIGHT = 300;
    const BAR_H  = 64;

    // Document title printed in the bottom bar, per print_setting.setting_for.
    public static $titles = array(
        'appointment'           => 'Appointment',
        'opdpre'                => 'OPD Prescription',
        'opd'                   => 'OPD Bill',
        'ipdpres'               => 'IPD Prescription',
        'ipd'                   => 'IPD Bill',
        'bill'                  => 'Bill Summary',
        'pharmacy'              => 'Pharmacy Bill',
        'payslip'               => 'Payslip',
        'paymentreceipt'        => 'Payment Receipt',
        'birth'                 => 'Birth Record',
        'death'                 => 'Death Record',
        'pathology'             => 'Pathology Report',
        'radiology'             => 'Radiology Report',
        'ot'                    => 'Operation Theatre',
        'bloodbank'             => 'Blood Bank',
        'ambulance'             => 'Ambulance',
        'discharge_card'        => 'Discharge Summary',
        'obstetric_history'     => 'Obstetric History',
        'opd_antenatal_finding' => 'OPD Antenatal Finding',
        'ipd_antenatal_finding' => 'IPD Antenatal Finding',
    );

    private $font_regular;
    private $font_medium;
    private $font_bold;
    private $logo_path;

    public function __construct()
    {
        $dir                = FCPATH . 'backend/dist/fonts/';
        $this->font_regular = $dir . 'Roboto-Regular.ttf';
        $this->font_medium  = $dir . 'Roboto-Medium.ttf';
        $this->font_bold    = $dir . 'Roboto-Bold.ttf';
        $logo               = FCPATH . 'backend/images/print_header_logo.png';
        $this->logo_path    = is_file($logo) ? $logo : null;
    }

    /**
     * Writes the header JPEG for one print type to $path.
     * $hospital needs name, address, phone, email.
     */
    public function save($hospital, $setting_for, $path)
    {
        $title = isset(self::$titles[$setting_for]) ? self::$titles[$setting_for] : ucwords(str_replace('_', ' ', $setting_for));

        $img   = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $white = imagecolorallocate($img, 255, 255, 255);
        $ink   = imagecolorallocate($img, 20, 24, 31);
        $muted = imagecolorallocate($img, 70, 78, 90);
        $brand = imagecolorallocate($img, 196, 30, 58);
        $bar   = imagecolorallocate($img, 11, 52, 120); // logo navy
        imagefill($img, 0, 0, $white);

        $top_h = self::HEIGHT - self::BAR_H;
        $pad   = 70;

        // Right column: contact lines, right-aligned, label in bold.
        $lines = array();
        foreach ($this->wrap('Address: ', trim((string) $hospital->address), 46, 2) as $line) {
            $lines[] = $line;
        }
        if (trim((string) $hospital->phone) !== '') {
            $lines[] = array('Phone No.: ', trim($hospital->phone));
        }
        if (trim((string) $hospital->email) !== '') {
            $lines[] = array('Email: ', trim($hospital->email));
        }

        $size       = 30;
        $line_h     = 46;
        $right_w    = 0;
        foreach ($lines as $l) {
            $right_w = max($right_w, $this->width($l[0], $this->font_medium, $size) + $this->width($l[1], $this->font_regular, $size));
        }
        $block_h = count($lines) * $line_h;
        $y       = (int) (($top_h - $block_h) / 2) + 34;
        foreach ($lines as $l) {
            $value_w = $this->width($l[1], $this->font_regular, $size);
            $label_w = $this->width($l[0], $this->font_medium, $size);
            $x       = self::WIDTH - $pad - $value_w - $label_w;
            imagettftext($img, $size, 0, $x, $y, $ink, $this->font_medium, $l[0]);
            imagettftext($img, $size, 0, $x + $label_w, $y, $muted, $this->font_regular, $l[1]);
            $y += $line_h;
        }

        // Left: the hospital logo (or a plain medical cross), then the hospital name
        // shrunk or wrapped to fit beside the contact block.
        $logo = $this->logo_path ? @imagecreatefrompng($this->logo_path) : false;
        if ($logo) {
            $logo_h = 170;
            $logo_w = (int) round(imagesx($logo) * $logo_h / imagesy($logo));
            imagecopyresampled($img, $logo, $pad, (int) (($top_h - $logo_h) / 2), 0, 0, $logo_w, $logo_h, imagesx($logo), imagesy($logo));
            imagedestroy($logo);
            $mark_w = $logo_w;
        } else {
            $mark_w = 110;
            $cy     = (int) (($top_h - $mark_w) / 2);
            $arm    = (int) ($mark_w / 3);
            imagefilledrectangle($img, $pad + $arm, $cy, $pad + 2 * $arm, $cy + $mark_w, $brand);
            imagefilledrectangle($img, $pad, $cy + $arm, $pad + $mark_w, $cy + 2 * $arm, $brand);
        }

        $name_x   = $pad + $mark_w + 40;
        $max_w    = self::WIDTH - $pad - $right_w - 80 - $name_x;
        $name       = trim((string) $hospital->name);
        $name_lines = array($name);
        $name_sz    = $this->fit($name_lines, $max_w, 72);
        if ($name_sz < 44 && strpos($name, ' ') !== false) {
            // Long names read better on two lines than shrunk to one.
            $name_lines = $this->split_in_two($name);
            $name_sz    = $this->fit($name_lines, $max_w, 60);
        }
        $line_gap = (int) ($name_sz * 1.3);
        $base     = (int) (($top_h - $line_gap * (count($name_lines) - 1)) / 2) + (int) ($name_sz * 0.5);
        foreach ($name_lines as $i => $text) {
            imagettftext($img, $name_sz, 0, $name_x, $base + $i * $line_gap, $ink, $this->font_bold, $text);
        }

        // Bottom bar with the document title, centred.
        imagefilledrectangle($img, 0, $top_h, self::WIDTH, self::HEIGHT, $bar);
        $t_size = 32;
        $t_w    = $this->width($title, $this->font_medium, $t_size);
        imagettftext($img, $t_size, 0, (int) ((self::WIDTH - $t_w) / 2), $top_h + 46, $white, $this->font_medium, $title);

        $ok = imagejpeg($img, $path, 92);
        imagedestroy($img);
        return $ok;
    }

    /** Largest font size (<= $start) at which every line fits in $max_w. */
    private function fit($lines, $max_w, $start)
    {
        $size = $start;
        foreach ($lines as $text) {
            while ($size > 24 && $this->width($text, $this->font_bold, $size) > $max_w) {
                $size -= 2;
            }
        }
        return $size;
    }

    /** Splits text at the word break that makes the two halves closest in length. */
    private function split_in_two($text)
    {
        $words = explode(' ', $text);
        $best  = array($text);
        $diff  = PHP_INT_MAX;
        for ($i = 1; $i < count($words); $i++) {
            $a = implode(' ', array_slice($words, 0, $i));
            $b = implode(' ', array_slice($words, $i));
            if (abs(strlen($a) - strlen($b)) < $diff) {
                $diff = abs(strlen($a) - strlen($b));
                $best = array($a, $b);
            }
        }
        return $best;
    }

    private function width($text, $font, $size)
    {
        $box = imagettfbbox($size, 0, $font, $text);
        return $box[2] - $box[0];
    }

    /** Splits a labelled value into at most $max_lines lines of about $chars characters. */
    private function wrap($label, $value, $chars, $max_lines)
    {
        if ($value === '') {
            return array();
        }
        $parts = explode("\n", wordwrap(preg_replace('/\s+/', ' ', $value), $chars, "\n"));
        if (count($parts) > $max_lines) {
            $parts = array_merge(array_slice($parts, 0, $max_lines - 1), array(implode(' ', array_slice($parts, $max_lines - 1))));
        }
        $lines = array(array($label, array_shift($parts)));
        foreach ($parts as $p) {
            $lines[] = array('', $p);
        }
        return $lines;
    }
}
