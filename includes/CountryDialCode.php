<?php

class CountryDialCode
{
    private PDO $conn;
    private string $table = 'country_dial_codes';

    public function __construct(PDO $db)
    {
        $this->conn = $db;
        $this->ensureTable();
        $this->seedDefaults();
    }

    public function listActive(): array
    {
        $sql = "SELECT dial_code, country_name FROM {$this->table} WHERE is_active = 1 ORDER BY country_name ASC";
        $stmt = $this->conn->query($sql);
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        $options = [];
        foreach ($rows as $row) {
            $code = preg_replace('/\D+/', '', (string)($row['dial_code'] ?? ''));
            $country = trim((string)($row['country_name'] ?? ''));
            if ($code === '' || $country === '') {
                continue;
            }
            $options[] = [
                'value' => $code,
                'label' => $country . ' (+' . $code . ')'
            ];
        }

        // Keep Kenya as the default option by always placing +254 first.
        usort($options, static function (array $left, array $right): int {
            if (($left['value'] ?? '') === '254') {
                return -1;
            }
            if (($right['value'] ?? '') === '254') {
                return 1;
            }

            return strcmp((string)($left['label'] ?? ''), (string)($right['label'] ?? ''));
        });

        return $options;
    }

    private function ensureTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            country_name VARCHAR(100) NOT NULL,
            iso2 CHAR(2) DEFAULT NULL,
            dial_code VARCHAR(8) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_iso2 (iso2),
            KEY idx_dial_code (dial_code),
            KEY idx_country_name (country_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->conn->exec($sql);

        $legacyUniqueDial = $this->indexExists('uniq_dial_code');
        if ($legacyUniqueDial) {
            $this->conn->exec("ALTER TABLE {$this->table} DROP INDEX uniq_dial_code");
        }

        if (!$this->indexExists('uniq_iso2')) {
            $this->conn->exec("ALTER TABLE {$this->table} ADD UNIQUE KEY uniq_iso2 (iso2)");
        }

        if (!$this->indexExists('idx_dial_code')) {
            $this->conn->exec("ALTER TABLE {$this->table} ADD KEY idx_dial_code (dial_code)");
        }
    }

    private function indexExists(string $indexName): bool
    {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
              AND index_name = :index_name"
        );
        $stmt->bindValue(':table_name', $this->table);
        $stmt->bindValue(':index_name', $indexName);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($row['total'] ?? 0) > 0;
    }

    private function seedDefaults(): void
    {
        $codes = [
            ['Afghanistan', 'AF', '93'],
            ['Albania', 'AL', '355'],
            ['Algeria', 'DZ', '213'],
            ['Andorra', 'AD', '376'],
            ['Angola', 'AO', '244'],
            ['Argentina', 'AR', '54'],
            ['Armenia', 'AM', '374'],
            ['Australia', 'AU', '61'],
            ['Austria', 'AT', '43'],
            ['Azerbaijan', 'AZ', '994'],
            ['Bahrain', 'BH', '973'],
            ['Bangladesh', 'BD', '880'],
            ['Belarus', 'BY', '375'],
            ['Belgium', 'BE', '32'],
            ['Benin', 'BJ', '229'],
            ['Bolivia', 'BO', '591'],
            ['Botswana', 'BW', '267'],
            ['Brazil', 'BR', '55'],
            ['Bulgaria', 'BG', '359'],
            ['Burkina Faso', 'BF', '226'],
            ['Burundi', 'BI', '257'],
            ['Cambodia', 'KH', '855'],
            ['Cameroon', 'CM', '237'],
            ['Canada', 'CA', '1'],
            ['Chad', 'TD', '235'],
            ['Chile', 'CL', '56'],
            ['China', 'CN', '86'],
            ['Colombia', 'CO', '57'],
            ['Congo', 'CG', '242'],
            ['Costa Rica', 'CR', '506'],
            ['Croatia', 'HR', '385'],
            ['Cyprus', 'CY', '357'],
            ['Czech Republic', 'CZ', '420'],
            ['Denmark', 'DK', '45'],
            ['Djibouti', 'DJ', '253'],
            ['DR Congo', 'CD', '243'],
            ['Egypt', 'EG', '20'],
            ['Eritrea', 'ER', '291'],
            ['Estonia', 'EE', '372'],
            ['Eswatini', 'SZ', '268'],
            ['Ethiopia', 'ET', '251'],
            ['Finland', 'FI', '358'],
            ['France', 'FR', '33'],
            ['Gabon', 'GA', '241'],
            ['Gambia', 'GM', '220'],
            ['Georgia', 'GE', '995'],
            ['Germany', 'DE', '49'],
            ['Ghana', 'GH', '233'],
            ['Greece', 'GR', '30'],
            ['Guinea', 'GN', '224'],
            ['Hungary', 'HU', '36'],
            ['India', 'IN', '91'],
            ['Indonesia', 'ID', '62'],
            ['Iran', 'IR', '98'],
            ['Iraq', 'IQ', '964'],
            ['Ireland', 'IE', '353'],
            ['Israel', 'IL', '972'],
            ['Italy', 'IT', '39'],
            ['Japan', 'JP', '81'],
            ['Jordan', 'JO', '962'],
            ['Kazakhstan', 'KZ', '7'],
            ['Kenya', 'KE', '254'],
            ['Kuwait', 'KW', '965'],
            ['Latvia', 'LV', '371'],
            ['Lebanon', 'LB', '961'],
            ['Lesotho', 'LS', '266'],
            ['Liberia', 'LR', '231'],
            ['Libya', 'LY', '218'],
            ['Lithuania', 'LT', '370'],
            ['Luxembourg', 'LU', '352'],
            ['Madagascar', 'MG', '261'],
            ['Malawi', 'MW', '265'],
            ['Malaysia', 'MY', '60'],
            ['Mali', 'ML', '223'],
            ['Malta', 'MT', '356'],
            ['Mauritania', 'MR', '222'],
            ['Mauritius', 'MU', '230'],
            ['Mexico', 'MX', '52'],
            ['Morocco', 'MA', '212'],
            ['Mozambique', 'MZ', '258'],
            ['Namibia', 'NA', '264'],
            ['Nepal', 'NP', '977'],
            ['Netherlands', 'NL', '31'],
            ['New Zealand', 'NZ', '64'],
            ['Niger', 'NE', '227'],
            ['Nigeria', 'NG', '234'],
            ['Norway', 'NO', '47'],
            ['Oman', 'OM', '968'],
            ['Pakistan', 'PK', '92'],
            ['Peru', 'PE', '51'],
            ['Philippines', 'PH', '63'],
            ['Poland', 'PL', '48'],
            ['Portugal', 'PT', '351'],
            ['Qatar', 'QA', '974'],
            ['Romania', 'RO', '40'],
            ['Russia', 'RU', '7'],
            ['Rwanda', 'RW', '250'],
            ['Saudi Arabia', 'SA', '966'],
            ['Senegal', 'SN', '221'],
            ['Serbia', 'RS', '381'],
            ['Sierra Leone', 'SL', '232'],
            ['Singapore', 'SG', '65'],
            ['Slovakia', 'SK', '421'],
            ['Slovenia', 'SI', '386'],
            ['Somalia', 'SO', '252'],
            ['South Africa', 'ZA', '27'],
            ['South Sudan', 'SS', '211'],
            ['Spain', 'ES', '34'],
            ['Sri Lanka', 'LK', '94'],
            ['Sudan', 'SD', '249'],
            ['Sweden', 'SE', '46'],
            ['Switzerland', 'CH', '41'],
            ['Syria', 'SY', '963'],
            ['Tanzania', 'TZ', '255'],
            ['Thailand', 'TH', '66'],
            ['Tunisia', 'TN', '216'],
            ['Turkey', 'TR', '90'],
            ['Uganda', 'UG', '256'],
            ['Ukraine', 'UA', '380'],
            ['United Arab Emirates', 'AE', '971'],
            ['United Kingdom', 'GB', '44'],
            ['United States', 'US', '1'],
            ['Uruguay', 'UY', '598'],
            ['Yemen', 'YE', '967'],
            ['Zambia', 'ZM', '260'],
            ['Zimbabwe', 'ZW', '263']
        ];

        $existingIso2 = [];
        $existingStmt = $this->conn->query("SELECT iso2 FROM {$this->table} WHERE iso2 IS NOT NULL AND iso2 <> ''");
        $existingRows = $existingStmt ? ($existingStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($existingRows as $row) {
            $iso2 = strtoupper(trim((string)($row['iso2'] ?? '')));
            if ($iso2 !== '') {
                $existingIso2[$iso2] = true;
            }
        }

        $sql = "INSERT INTO {$this->table} (country_name, iso2, dial_code, is_active) VALUES (:country_name, :iso2, :dial_code, 1)";
        $stmt = $this->conn->prepare($sql);

        foreach ($codes as $item) {
            [$country, $iso2, $dial] = $item;
            if (isset($existingIso2[$iso2])) {
                continue;
            }

            $stmt->bindValue(':country_name', $country);
            $stmt->bindValue(':iso2', $iso2);
            $stmt->bindValue(':dial_code', $dial);
            $stmt->execute();
        }
    }
}
