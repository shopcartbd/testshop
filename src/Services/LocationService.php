<?php
declare(strict_types=1);
namespace AllStore\Services;

use AllStore\Core\Helpers;

/**
 * Admin-managed Bangladesh delivery hierarchy.
 *
 * The hierarchy is stored as one JSON document in the existing `settings`
 * table, so this feature works on the already-deployed 58-table schema without
 * asking the merchant to run another SQL migration. The bundled seed covers all
 * 8 divisions, 64 districts and 495 upazilas; administrators can add, rename,
 * deactivate, delete and restore divisions, districts, upazilas and thanas.
 */
final class LocationService
{
    private static ?array $defaults = null;

    public static function defaultPath(): string
    {
        return APP_ROOT . '/config/bd_locations.default.json';
    }

    public static function defaultData(): array
    {
        if (self::$defaults !== null) { return self::$defaults; }
        $raw = is_file(self::defaultPath()) ? (string)file_get_contents(self::defaultPath()) : '';
        $data = $raw !== '' ? (array)Helpers::decode($raw, []) : [];
        return self::$defaults = self::normalize($data);
    }

    public static function ensureDefaults(): void
    {
        try {
            if (Helpers::setting('checkout_default_country', '') === '') {
                Helpers::setSetting('checkout_default_country', 'BD', 'checkout');
            }
            if (Helpers::setting('bd_locations_version', '') === '') {
                Helpers::setSetting('bd_locations_version', '1', 'checkout');
            }
            self::syncDefaults();
        } catch (\Throwable) {
            // Checkout must remain usable even if a read-only replica denies the write.
        }
        DeliveryZoneService::ensureDefaults();
    }

    /**
     * Bundled seed revision. A store that already saved the hierarchy keeps its
     * own edits, but missing records from a newer bundled seed (for example the
     * Dhaka city thanas that make the “Dhaka city” delivery rate reachable) are
     * merged in once, without any SQL migration and without touching stored
     * names, order or is_active flags.
     */
    public static function seedRevision(): int
    {
        $defaults = self::defaultData();
        return max(1, (int)($defaults['_seed_rev'] ?? $defaults['version'] ?? 1));
    }

    private static function syncDefaults(): void
    {
        $raw = Helpers::setting('bd_locations_json', '');
        if ($raw === '') { return; } // nothing saved yet: the bundled seed is used directly
        $stored = Helpers::decode($raw, []);
        if (!is_array($stored) || empty($stored['divisions']) || !is_array($stored['divisions'])) { return; }
        $revision = self::seedRevision();
        if ((int)($stored['_seed_rev'] ?? 0) >= $revision) { return; }
        $defaults = self::defaultData();
        $merged = self::mergeMissing($stored, $defaults);
        $merged['_seed_rev'] = $revision;
        $merged['version'] = max((int)($stored['version'] ?? 1), (int)($defaults['version'] ?? 1));
        Helpers::setSetting('bd_locations_json', Helpers::json($merged), 'checkout');
        Helpers::setSetting('bd_locations_version', (string)$merged['version'], 'checkout');
    }

    /** Add only the bundled records the stored document does not have yet. */
    private static function mergeMissing(array $stored, array $defaults): array
    {
        if (($stored['_attribution'] ?? '') === '') { $stored['_attribution'] = (string)($defaults['_attribution'] ?? ''); }
        if (($stored['_license'] ?? '') === '') { $stored['_license'] = (string)($defaults['_license'] ?? ''); }
        $divIndex = [];
        foreach ($stored['divisions'] as $i => $d) {
            if (!is_array($d)) { continue; }
            $divIndex['id:' . (string)($d['id'] ?? '')] = $i;
            $divIndex['name:' . self::name($d)] = $i;
        }
        foreach ((array)($defaults['divisions'] ?? []) as $dd) {
            if (!is_array($dd)) { continue; }
            $divPos = $divIndex['id:' . (string)($dd['id'] ?? '')] ?? $divIndex['name:' . self::name($dd)] ?? null;
            if ($divPos === null || !is_array($stored['divisions'][$divPos] ?? null)) {
                $stored['divisions'][] = $dd;
                $divIndex['id:' . (string)($dd['id'] ?? '')] = array_key_last($stored['divisions']);
                continue;
            }
            $distIndex = [];
            foreach ((array)($stored['divisions'][$divPos]['districts'] ?? []) as $j => $x) {
                if (!is_array($x)) { continue; }
                $distIndex['id:' . (string)($x['id'] ?? '')] = $j;
                $distIndex['name:' . self::name($x)] = $j;
            }
            foreach ((array)($dd['districts'] ?? []) as $dx) {
                if (!is_array($dx)) { continue; }
                $distPos = $distIndex['id:' . (string)($dx['id'] ?? '')] ?? $distIndex['name:' . self::name($dx)] ?? null;
                if ($distPos === null || !is_array($stored['divisions'][$divPos]['districts'][$distPos] ?? null)) {
                    $stored['divisions'][$divPos]['districts'][] = $dx;
                    $distIndex['id:' . (string)($dx['id'] ?? '')] = array_key_last($stored['divisions'][$divPos]['districts']);
                    continue;
                }
                $areaKeys = [];
                foreach ((array)($stored['divisions'][$divPos]['districts'][$distPos]['areas'] ?? []) as $a) {
                    if (!is_array($a)) { continue; }
                    $areaKeys['id:' . (string)($a['id'] ?? '')] = true;
                    $areaKeys['name:' . self::name($a)] = true;
                }
                foreach ((array)($dx['areas'] ?? []) as $da) {
                    if (!is_array($da)) { continue; }
                    if (isset($areaKeys['id:' . (string)($da['id'] ?? '')]) || isset($areaKeys['name:' . self::name($da)])) { continue; }
                    $stored['divisions'][$divPos]['districts'][$distPos]['areas'][] = $da;
                    $areaKeys['id:' . (string)($da['id'] ?? '')] = true;
                }
            }
        }
        return $stored;
    }

    public static function defaultCountry(): string
    {
        $v = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', Helpers::setting('checkout_default_country', 'BD')) ?: 'BD', 0, 2));
        return $v !== '' ? $v : 'BD';
    }

    /** Full editable hierarchy, including inactive records. */
    public static function all(): array
    {
        $raw = Helpers::setting('bd_locations_json', '');
        if ($raw === '') { return self::defaultData(); }
        $data = Helpers::decode($raw, []);
        if (!is_array($data) || empty($data['divisions'])) { return self::defaultData(); }
        return self::normalize($data);
    }

    public static function save(array $data): array
    {
        $data = self::normalize($data);
        Helpers::setSetting('bd_locations_json', Helpers::json($data), 'checkout');
        Helpers::setSetting('bd_locations_version', (string)($data['version'] ?? 1), 'checkout');
        return $data;
    }

    public static function reset(): array
    {
        $default = self::defaultData();
        $default['version'] = (int)($default['version'] ?? 1) + 1;
        return self::save($default);
    }

    public static function normalize(mixed $data): array
    {
        $divisions = is_array($data) && isset($data['divisions']) && is_array($data['divisions']) ? $data['divisions'] : [];
        $out = [
            '_attribution' => is_array($data) ? (string)($data['_attribution'] ?? '') : '',
            '_license' => is_array($data) ? (string)($data['_license'] ?? '') : '',
            '_seed_rev' => is_array($data) ? max(0, (int)($data['_seed_rev'] ?? 0)) : 0,
            'version' => max(1, (int)(is_array($data) ? ($data['version'] ?? 1) : 1)),
            'divisions' => [],
        ];
        foreach (array_slice($divisions, 0, 30) as $d) {
            if (!is_array($d)) { continue; }
            $name = self::name($d);
            $en = self::english($d);
            if ($name === '') { continue; }
            $division = [
                'id' => self::id($d, 'division', $name, $en),
                'name' => $name,
                'en' => $en,
                'is_active' => self::active($d),
                'districts' => [],
            ];
            foreach (array_slice((array)($d['districts'] ?? []), 0, 120) as $x) {
                if (!is_array($x)) { continue; }
                $dName = self::name($x);
                $dEn = self::english($x);
                if ($dName === '') { continue; }
                $district = [
                    'id' => self::id($x, 'district', $dName, $dEn, $division['id']),
                    'name' => $dName,
                    'en' => $dEn,
                    'is_active' => self::active($x),
                    'areas' => [],
                ];
                foreach (array_slice((array)($x['areas'] ?? []), 0, 1200) as $a) {
                    if (!is_array($a)) { continue; }
                    $aName = self::name($a);
                    $aEn = self::english($a);
                    if ($aName === '') { continue; }
                    $type = strtolower((string)($a['type'] ?? 'upazila'));
                    if (!in_array($type, ['upazila','thana'], true)) { $type = 'upazila'; }
                    $district['areas'][] = [
                        'id' => self::id($a, 'area', $aName, $aEn, $district['id']),
                        'name' => $aName,
                        'en' => $aEn,
                        'type' => $type,
                        'is_active' => self::active($a),
                    ];
                }
                $division['districts'][] = $district;
            }
            $out['divisions'][] = $division;
        }
        return $out;
    }

    public static function publicData(): array
    {
        $out = ['version'=>1, 'divisions'=>[]];
        foreach (self::all()['divisions'] as $d) {
            if (!self::active($d)) { continue; }
            $division = ['id'=>$d['id'],'name'=>$d['name'],'en'=>$d['en'],'districts'=>[]];
            foreach ($d['districts'] as $x) {
                if (!self::active($x)) { continue; }
                $district = ['id'=>$x['id'],'name'=>$x['name'],'en'=>$x['en'],'areas'=>[]];
                foreach ($x['areas'] as $a) {
                    if (!self::active($a)) { continue; }
                    $district['areas'][] = ['id'=>$a['id'],'name'=>$a['name'],'en'=>$a['en'],'type'=>$a['type']];
                }
                $division['districts'][] = $district;
            }
            $out['divisions'][] = $division;
        }
        $out['version'] = (int)(self::all()['version'] ?? 1);
        return $out;
    }

    public static function counts(array $data = []): array
    {
        $data = $data ?: self::all();
        $districts = 0; $areas = 0; $thanas = 0; $activeAreas = 0;
        foreach ($data['divisions'] ?? [] as $d) {
            $districts += count($d['districts'] ?? []);
            foreach ($d['districts'] ?? [] as $x) {
                foreach ($x['areas'] ?? [] as $a) {
                    $areas++;
                    if (($a['type'] ?? '') === 'thana') { $thanas++; }
                    if (self::active($a)) { $activeAreas++; }
                }
            }
        }
        return ['divisions'=>count($data['divisions'] ?? []),'districts'=>$districts,'areas'=>$areas,'thanas'=>$thanas,'active_areas'=>$activeAreas];
    }

    /**
     * Validate a submitted BD hierarchy selection and return both errors and
     * normalised labels for shipping-zone matching and the saved address.
     */
    public static function resolve(string $country, string $divisionId = '', string $districtId = '', string $areaId = ''): array
    {
        $country = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $country) ?: '', 0, 2));
        $empty = ['errors'=>[], 'location'=>['country'=>$country,'division'=>'','division_en'=>'','district'=>'','district_en'=>'','area'=>'','area_en'=>'','area_type'=>'','division_id'=>'','district_id'=>'','area_id'=>'','city'=>'','region'=>'']];
        if ($country !== 'BD') { return $empty; }
        $ids = array_filter([$divisionId,$districtId,$areaId], static fn($v)=>$v !== '');
        if ($ids === []) { return $empty; }
        // Older simplified BD submissions or saved addresses can arrive with
        // district/area but no division; resolve it before validation.
        if ($divisionId === '' && $districtId !== '' && $areaId !== '') {
            foreach (self::publicData()['divisions'] as $d) {
                foreach ($d['districts'] ?? [] as $x) {
                    if (($x['id'] ?? '') === $districtId) { $divisionId = (string)$d['id']; break 2; }
                }
            }
        }
        $errors = [];
        if ($divisionId === '' || $districtId === '' || $areaId === '') {
            $errors['area_id'] = 'Please complete the delivery area.';
            return ['errors'=>$errors,'location'=>$empty['location']];
        }
        foreach (self::publicData()['divisions'] as $d) {
            if ($d['id'] !== $divisionId) { continue; }
            foreach ($d['districts'] as $x) {
                if ($x['id'] !== $districtId) { continue; }
                foreach ($x['areas'] as $a) {
                    if ($a['id'] !== $areaId) { continue; }
                    return ['errors'=>[], 'location'=>[
                        'country'=>'BD','division'=>$d['name'],'division_en'=>$d['en'],
                        'district'=>$x['name'],'district_en'=>$x['en'],
                        'area'=>$a['name'],'area_en'=>$a['en'],'area_type'=>$a['type'],
                        'division_id'=>$d['id'],'district_id'=>$x['id'],'area_id'=>$a['id'],
                        'city'=>$a['name'],'region'=>trim($d['name'].' / '.$x['name']),
                    ]];
                }
            }
        }
        $errors['area_id'] = 'The selected delivery area is no longer available. Please choose it again.';
        return ['errors'=>$errors,'location'=>$empty['location']];
    }

    public static function newId(string $level, string $name, string $parent = ''): string
    {
        $slug = Helpers::slugify($name);
        if ($slug === '') { $slug = substr(hash('sha256', $name), 0, 8); }
        return strtoupper(substr($level, 0, 1)) . '-' . substr($slug, 0, 18) . '-' . substr(hash('sha256', $parent . '|' . $name . '|' . Helpers::token(5)), 0, 8);
    }

    private static function id(array $row, string $level, string $name, string $en, string $parent = ''): string
    {
        $v = trim((string)($row['id'] ?? ''));
        return preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $v) ? $v : self::newId($level, $en !== '' ? $en : $name, $parent);
    }

    private static function name(array $row): string
    {
        return mb_substr(trim((string)($row['name'] ?? '')), 0, 120);
    }

    private static function english(array $row): string
    {
        return mb_substr(trim((string)($row['en'] ?? '')), 0, 120);
    }

    private static function active(array $row): int
    {
        return array_key_exists('is_active', $row) ? ((int)$row['is_active'] ? 1 : 0) : 1;
    }
}
