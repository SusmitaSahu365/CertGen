<?php
namespace CertGen;

/**
 * Ready-made templates. These are generic layouts (no institution branding);
 * the institution name, address and logo come from Settings.
 */
class Presets
{
    private static function f(string $key, string $label, string $type = 'text', bool $req = false): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type, 'required' => $req];
    }

    private static function b(string $type, string $a = '', string $b = '', array $x = []): array
    {
        return array_merge(['type' => $type, 'a' => $a, 'b' => $b, 'align' => $type === 'heading' ? 'C' : 'L', 'size' => 0, 'bold' => false], $x);
    }

    /** @return array<string,array> slug => preset definition */
    public static function all(): array
    {
        $he = 'M=He,MALE=He,F=She,FEMALE=She,*=He/She';
        $his = 'M=his,MALE=his,F=her,FEMALE=her,*=his/her';
        $title = 'M=Mr.,MALE=Mr.,F=Ms.,FEMALE=Ms.,*=Mr./Ms.';

        return [
            'leaving' => [
                'name'        => 'Leaving Certificate',
                'description' => 'Issued when a student leaves the institution. Records admission, conduct and reason for leaving.',
                'fields'      => [
                    self::f('gen_reg_no', 'General Register No.', 'text', true),
                    self::f('full_name', 'Full Name', 'text', true),
                    self::f('mother_name', "Mother's Name"),
                    self::f('caste', 'Race and Caste'),
                    self::f('sub_caste', 'Sub-caste'),
                    self::f('nationality', 'Nationality'),
                    self::f('enrollment_no', 'Enrollment No.'),
                    self::f('place_of_birth', 'Place of Birth'),
                    self::f('date_of_birth', 'Date of Birth', 'date', true),
                    self::f('last_institution', 'Last School / College Attended'),
                    self::f('admission_date', 'Date of Admission', 'date'),
                    self::f('progress', 'Progress'),
                    self::f('conduct', 'Conduct'),
                    self::f('leaving_date', 'Date of Leaving', 'date'),
                    self::f('course', 'Course'),
                    self::f('year_of_study', 'Year of Study'),
                    self::f('reason', 'Reason for Leaving'),
                    self::f('remarks', 'Remarks'),
                ],
                'blocks'      => [
                    self::b('heading', 'LEAVING CERTIFICATE', '', ['size' => 18]),
                    self::b('text', 'No change in any entry of this certificate may be made except by the authority issuing it. Any infringement of this requirement is liable to involve the imposition of a penalty.', '', ['align' => 'C', 'size' => 8]),
                    self::b('spacer', '', '', ['size' => 2]),
                    self::b('row', 'Sr. No.: **{{serial_no}}**', 'Gen. Reg. No.: **{{gen_reg_no}}**'),
                    self::b('spacer', '', '', ['size' => 2]),
                    self::b('kv', '01. Name of the candidate (in full)', '{{full_name|upper}}'),
                    self::b('kv', "Mother's name", '{{mother_name}}'),
                    self::b('row', '02. Race and caste: **{{caste}}**', 'Sub-caste: **{{sub_caste}}**'),
                    self::b('row', '03. Nationality: **{{nationality}}**', 'Enrollment No.: **{{enrollment_no}}**'),
                    self::b('kv', '04. Place of birth', '{{place_of_birth}}'),
                    self::b('kv', '05. Date of birth (in figures)', '{{date_of_birth}}'),
                    self::b('kv', 'Date of birth (in words)', '{{date_of_birth|date_words}}'),
                    self::b('kv', '06. Last school / college attended', '{{last_institution}}'),
                    self::b('kv', '07. Date of admission', '{{admission_date}}'),
                    self::b('row', '08. Progress: **{{progress}}**', '09. Conduct: **{{conduct}}**'),
                    self::b('kv', '10. Date of leaving', '{{leaving_date}}'),
                    self::b('kv', '11. Course in which studying', '{{course}}'),
                    self::b('kv', '12. Year of study', '{{year_of_study}}'),
                    self::b('kv', '13. Reason for leaving', '{{reason}}'),
                    self::b('kv', '14. Remarks', '{{remarks}}'),
                ],
                'options'     => ['base_size' => 11, 'footer_note' => 'Certified that the above information is in accordance with the institution register.', 'signatures' => 'Prepared by, Checked by, Principal'],
            ],

            'bonafide' => [
                'name'        => 'Bonafide Certificate',
                'description' => 'Confirms that a person is a current student. Gender-aware wording (Mr./Ms., he/she) is filled from one column.',
                'fields'      => [
                    self::f('full_name', 'Full Name', 'text', true),
                    self::f('gender', 'Gender (M/F)'),
                    self::f('enrollment_no', 'Enrollment No.'),
                    self::f('roll_no', 'Roll No.'),
                    self::f('seat_no', 'Exam Seat No.'),
                    self::f('course', 'Course', 'text', true),
                    self::f('year_of_study', 'Year of Study'),
                    self::f('academic_year', 'Academic Year'),
                    self::f('date_of_birth', 'Date of Birth', 'date'),
                    self::f('purpose', 'Purpose'),
                ],
                'blocks'      => [
                    self::b('row', 'Ref. No.: {{record_no}}', 'Date: {{issue_date}}', ['align' => 'R']),
                    self::b('spacer', '', '', ['size' => 6]),
                    self::b('heading', 'BONAFIDE CERTIFICATE', '', ['size' => 20]),
                    self::b('spacer', '', '', ['size' => 8]),
                    self::b('text', "This is to certify that {{gender|map:$title}} **{{full_name|upper}}** is a bonafide student of this institution, studying in **{{year_of_study}}** of **{{course}}** for the academic year **{{academic_year}}**.", '', ['size' => 13]),
                    self::b('text', 'Roll No.: **{{roll_no}}**      Examination Seat No.: **{{seat_no}}**', '', ['size' => 13]),
                    self::b('text', 'Enrollment No.: **{{enrollment_no}}**      Date of Birth: **{{date_of_birth}}**', '', ['size' => 13]),
                    self::b('text', "{{gender|map:$he}} bears a good moral character and conduct.", '', ['size' => 13]),
                    self::b('text', "This certificate is issued on {{gender|map:$his}} request for the purpose of: **{{purpose}}**.", '', ['size' => 13]),
                ],
                'options'     => ['base_size' => 13, 'signatures' => 'Authorised signatory'],
            ],
        ];
    }

    public static function install(string $slug): ?CertTemplate
    {
        $p = self::all()[$slug] ?? null;
        if (!$p) {
            return null;
        }
        $t = new CertTemplate();
        $t->name = $p['name'];
        $t->description = $p['description'];
        $t->fields = $p['fields'];
        $t->blocks = array_map(function ($b) {
            $b['size'] = $b['size'] ?: ['heading' => 18, 'spacer' => 6][$b['type']] ?? 0;
            return $b;
        }, $p['blocks']);
        $t->options = array_merge(CertTemplate::defaultOptions(), $p['options']);
        $t->save();
        return $t;
    }
}
