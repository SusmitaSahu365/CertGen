<?php
namespace CertGen;

/**
 * A certificate template = data fields + a list of layout blocks + page options.
 * Stored as JSON, so any kind of certificate can be described without code changes.
 */
class CertTemplate
{
    public const FIELD_TYPES = ['text', 'date', 'number'];
    public const BLOCK_TYPES = ['heading', 'text', 'kv', 'row', 'spacer', 'rule'];
    public const FONTS = ['Times', 'Helvetica', 'Courier'];
    /** Names the system fills in itself; a field may not reuse them. */
    public const RESERVED = ['serial_no', 'record_no', 'issue_date', 'today', 'org_name', 'org_address', 'org_contact', 'place'];

    public ?int $id = null;
    public string $name = '';
    public string $description = '';
    public array $fields = [];   // [{key,label,type,required}]
    public array $blocks = [];   // [{type,a,b,align,size,bold}]
    public array $options = [];

    public static function defaultOptions(): array
    {
        return [
            'font'        => 'Times',
            'base_size'   => 12,
            'show_header' => 1,
            'border'      => 1,
            'place_date'  => 1,
            'footer_note' => '',
            'signatures'  => 'Prepared by, Checked by, Authorised signatory',
        ];
    }

    // ---- persistence ------------------------------------------------------

    public static function fromRow(array $r): self
    {
        $t = new self();
        $t->id = (int) $r['id'];
        $t->name = $r['name'];
        $t->description = $r['description'];
        $t->fields = json_decode($r['fields_json'], true) ?: [];
        $t->blocks = json_decode($r['blocks_json'], true) ?: [];
        $t->options = array_merge(self::defaultOptions(), json_decode($r['options_json'], true) ?: []);
        return $t;
    }

    public static function find(int $id): ?self
    {
        $r = Db::one('SELECT * FROM templates WHERE id = ?', [$id]);
        return $r ? self::fromRow($r) : null;
    }

    /** @return self[] */
    public static function all(): array
    {
        return array_map([self::class, 'fromRow'], Db::all('SELECT * FROM templates ORDER BY name'));
    }

    public function save(): int
    {
        $enc = fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($this->id) {
            Db::run(
                'UPDATE templates SET name=?, description=?, fields_json=?, blocks_json=?, options_json=?, updated_at=? WHERE id=?',
                [$this->name, $this->description, $enc($this->fields), $enc($this->blocks), $enc($this->options), now(), $this->id]
            );
            return $this->id;
        }
        return $this->id = Db::insert(
            'INSERT INTO templates (name, description, fields_json, blocks_json, options_json, created_at, updated_at) VALUES (?,?,?,?,?,?,?)',
            [$this->name, $this->description, $enc($this->fields), $enc($this->blocks), $enc($this->options), now(), now()]
        );
    }

    public static function delete(int $id): void
    {
        Db::run('DELETE FROM templates WHERE id = ?', [$id]);
    }

    public function fieldTypes(): array
    {
        $out = [];
        foreach ($this->fields as $f) {
            $out[$f['key']] = $f['type'];
        }
        return $out;
    }

    /** Example values used for the PDF preview and the downloadable sample sheet. */
    public function sampleData(): array
    {
        $out = [];
        foreach ($this->fields as $f) {
            $out[$f['key']] = match ($f['type']) {
                'date'   => '2005-06-12',
                'number' => '12345',
                default  => 'Sample ' . $f['label'],
            };
        }
        return $out;
    }

    // ---- building from the editor form -------------------------------------

    public static function keyFromLabel(string $label): string
    {
        $k = slugify($label, '_');
        if ($k === '' || ctype_digit($k[0])) {
            $k = 'f_' . $k;
        }
        return $k;
    }

    /**
     * Turn the submitted editor form into a template.
     * @return array{0:self,1:string[],2:string[]} [template, errors, warnings]
     */
    public static function fromPost(array $p, ?self $existing = null): array
    {
        $errors = [];
        $warnings = [];
        $t = $existing ?: new self();
        $t->name = trim((string) ($p['name'] ?? ''));
        $t->description = mb_substr(trim((string) ($p['description'] ?? '')), 0, 500);
        if ($t->name === '') {
            $errors[] = 'Give the template a name.';
        }

        // fields
        $t->fields = [];
        $seen = [];
        $labels = (array) ($p['f_label'] ?? []);
        foreach ($labels as $i => $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }
            $key = strtolower(trim((string) ($p['f_key'][$i] ?? '')));
            $key = preg_match('/^[a-z][a-z0-9_]*$/', $key) ? $key : self::keyFromLabel($label);
            if (in_array($key, self::RESERVED, true)) {
                $errors[] = "\"$key\" is reserved by the system. Rename the field \"$label\".";
            }
            if (isset($seen[$key])) {
                $errors[] = "Two fields share the key \"$key\". Keys must be unique.";
            }
            $seen[$key] = true;
            $type = $p['f_type'][$i] ?? 'text';
            $t->fields[] = [
                'key'      => $key,
                'label'    => mb_substr($label, 0, 100),
                'type'     => in_array($type, self::FIELD_TYPES, true) ? $type : 'text',
                'required' => !empty($p['f_req'][$i]) && $p['f_req'][$i] === '1',
            ];
        }
        if (!$t->fields) {
            $errors[] = 'Add at least one field.';
        }

        // blocks
        $t->blocks = [];
        foreach ((array) ($p['b_type'] ?? []) as $i => $type) {
            if (!in_array($type, self::BLOCK_TYPES, true)) {
                continue;
            }
            $a = (string) ($p['b_a'][$i] ?? '');
            $b = (string) ($p['b_b'][$i] ?? '');
            if (in_array($type, ['heading', 'text', 'kv', 'row'], true) && trim($a . $b) === '') {
                continue;
            }
            $align = $p['b_align'][$i] ?? '';
            $default = ['heading' => 18, 'text' => 0, 'kv' => 0, 'row' => 0, 'spacer' => 6, 'rule' => 0][$type];
            $size = (int) ($p['b_size'][$i] ?? 0);
            $t->blocks[] = [
                'type'  => $type,
                'a'     => $a,
                'b'     => $b,
                'align' => in_array($align, ['L', 'C', 'R', 'J'], true) ? $align : ($type === 'heading' ? 'C' : 'L'),
                'size'  => $size > 0 ? min($size, 60) : $default,
                'bold'  => ($p['b_bold'][$i] ?? '') === '1',
            ];
        }
        if (!$t->blocks) {
            $errors[] = 'Add at least one layout block (for example a heading).';
        }

        // options
        $o = self::defaultOptions();
        $font = $p['o_font'] ?? 'Times';
        $o['font'] = in_array($font, self::FONTS, true) ? $font : 'Times';
        $o['base_size'] = max(8, min(18, (int) ($p['o_base_size'] ?? 12)));
        $o['show_header'] = !empty($p['o_show_header']) ? 1 : 0;
        $o['border'] = !empty($p['o_border']) ? 1 : 0;
        $o['place_date'] = !empty($p['o_place_date']) ? 1 : 0;
        $o['footer_note'] = mb_substr(trim((string) ($p['o_footer_note'] ?? '')), 0, 300);
        $o['signatures'] = mb_substr(trim((string) ($p['o_signatures'] ?? '')), 0, 300);
        $t->options = $o;

        // warn about placeholders that match no field
        $known = array_merge(array_keys($t->fieldTypes()), self::RESERVED);
        $used = [];
        foreach ($t->blocks as $bl) {
            $used = array_merge($used, Placeholder::keysIn($bl['a']), Placeholder::keysIn($bl['b']));
        }
        $used = array_merge($used, Placeholder::keysIn($o['footer_note']));
        foreach (array_unique($used) as $k) {
            if (!in_array($k, $known, true)) {
                $warnings[] = "{{" . $k . "}} is used in the layout but is not a field. It will print blank.";
            }
        }
        return [$t, $errors, $warnings];
    }
}
