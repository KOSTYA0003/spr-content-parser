 <?php
    set_time_limit(100);
require_once __DIR__.'/vendor/autoload.php';

use DiDom\Document;

class SprRuParser
{
    private $proxies;

    private $failedProxies = [];

    private $baseDelay = 3;

    private $delayGrowthFactor = 5;

    private $maxAllowedDelay = 20;

    private $currentDelay;

    private $crawlDelayChecked = false;

    private $useProxy = true;

    private $directDelay = 5;

    private $lastUsedProxyId = null;

    private $targetSite = 'https://www.spr.ru/all/';

    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

    private $imageBaseDir = __DIR__.'/company_images/';

    private $db;

    private $dbHost = '';

    private $dbName = '';

    private $dbUser = '';

    private $dbPass = '';

    public function __construct(array $proxies, bool $useProxy = true)
    {
        $this->useProxy = $useProxy;

        foreach ($proxies as $proxy) {
            $parts = explode(':', $proxy['ip:port:login:password']);
            if (count($parts) !== 4) {
                throw new Exception('Неверный формат прокси: '.$proxy['ip:port:login:password']);
            }

            $this->proxies[$proxy['id']] = [
                'id' => $proxy['id'],
                'ip_port' => $parts[0].':'.$parts[1],
                'login' => $parts[2],
                'password' => $parts[3],
            ];
        }

        $this->currentDelay = $this->baseDelay;
        $this->createDataTables();
        $this->createCacheTables();
        $this->createImageDir();
        $this->clearLogs();
    }

    private function createDataTables()
    {
        try {
            $this->db = new PDO(
                "mysql:host={$this->dbHost};dbname={$this->dbName}",
                $this->dbUser,
                $this->dbPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
                ]
            );

            $this->db->exec('
                CREATE TABLE IF NOT EXISTS categories_l1 (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    url VARCHAR(512) NOT NULL UNIQUE,
                    processed BOOLEAN DEFAULT FALSE,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ');

            $this->db->exec('
                CREATE TABLE IF NOT EXISTS categories_l2 (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                url VARCHAR(512) NOT NULL,
                rubr_show VARCHAR(50) NOT NULL,
                l1_id INT NOT NULL,
                processed BOOLEAN DEFAULT FALSE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (l1_id) REFERENCES categories_l1(id),
                UNIQUE KEY (rubr_show, l1_id)
            );
');

            $this->db->exec('
                CREATE TABLE IF NOT EXISTS categories_l3 (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    url VARCHAR(512) NOT NULL UNIQUE,
                    l1_id INT NOT NULL,
                    l2_id INT NOT NULL,
                    processed BOOLEAN DEFAULT FALSE,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (l1_id) REFERENCES categories_l1(id),
                    FOREIGN KEY (l2_id) REFERENCES categories_l2(id),
                    UNIQUE KEY (url, l2_id)
                );
            ');

            $this->db->exec('
            CREATE TABLE IF NOT EXISTS companies (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                url VARCHAR(512) NOT NULL,
                l1_id INT NOT NULL,
                l2_id INT NOT NULL,
                l3_id INT NOT NULL,
                address TEXT,
                good_reviews INT DEFAULT 0,
                bad_reviews INT DEFAULT 0,
                phone_image_path VARCHAR(512),
                company_name_for_path VARCHAR(255) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (l1_id) REFERENCES categories_l1(id),
                FOREIGN KEY (l2_id) REFERENCES categories_l2(id),
                FOREIGN KEY (l3_id) REFERENCES categories_l3(id),
                UNIQUE KEY (url)
            );
        ');

            $this->db->exec('
                CREATE TABLE IF NOT EXISTS parser_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    message TEXT NOT NULL,
                    is_error TINYINT(1) DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
            ');
        } catch (PDOException $e) {
            throw new Exception('Ошибка подключения к БД: '.$e->getMessage());
        }
    }

    private function createImageDir()
    {
        if (! file_exists($this->imageBaseDir)) {
            mkdir($this->imageBaseDir, 0777, true);
        }
    }

    private function createCacheTables()
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS page_cache (
                id INT AUTO_INCREMENT PRIMARY KEY,
                url VARCHAR(512) NOT NULL UNIQUE,
                html_content LONGTEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            );
        ');
    }

    private function clearLogs()
    {
        $this->db->exec('TRUNCATE TABLE parser_logs');
    }

    public function run()
    {
        $this->log('=== Запуск парсера ===');
        $this->log('Режим работы: '.($this->useProxy ? 'с прокси' : 'без прокси'));

        try {
            $this->log('Получение категорий первого уровня (L1)');
            $l1Categories = $this->getL1Categories();
            $this->saveL1Categories($l1Categories);

            $l1ToProcess = $this->getAllCategories('categories_l1');
            $this->log('Будет обработано категорий L1: '.count($l1ToProcess));

            foreach ($l1ToProcess as $l1Category) {
                $this->processL1Category($l1Category);
            }

            $this->log('Парсинг успешно завершен!');
        } catch (Exception $e) {
            $this->log('Ошибка парсинга: '.$e->getMessage(), true);
        }

        $this->log('=== Завершение работы ===');
    }

    private function getL1Categories(): array
    {
        $result = $this->makeRequest($this->targetSite);

        if (! $result['success']) {
            throw new Exception('Не удалось загрузить главную страницу');
        }

        $content = $result['response'];

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = iconv('Windows-1251', 'UTF-8//IGNORE', $content);
        }

        $document = new Document($result['response']);
        $categories = [];

        $categoryElements = $document->find('div.rubricListCategories div.category');
        foreach ($categoryElements as $element) {
            $link = $element->first('a.categoryInfo');
            if ($link) {
                $name = trim($link->first('span.categoryName')->text());
                $url = $this->normalizeUrl($link->attr('href'), $this->targetSite, false);

                $categories[] = [
                    'name' => $name,
                    'url' => $url,
                ];
            }
        }

        return $categories;
    }

    private function getTableFields(string $table): array
    {
        $fieldsMap = [
            'categories_l1' => ['id', 'name', 'url'],
            'categories_l2' => ['id', 'name', 'url', 'rubr_show', 'l1_id'],
            'categories_l3' => ['id', 'name', 'url', 'l1_id', 'l2_id'],
            'companies' => ['id', 'name', 'url', 'l1_id', 'l2_id', 'l3_id'],
        ];

        return $fieldsMap[$table] ?? ['id', 'name', 'url'];
    }

    private function getAllCategories(string $table, ?int $l1Id = null, ?int $l2Id = null): array
    {
        try {
            $fields = $this->getTableFields($table);
            $fieldsStr = implode(', ', $fields);

            $sql = "SELECT {$fieldsStr} FROM {$table} WHERE processed = FALSE";
            $params = [];

            if ($l1Id !== null) {
                $sql .= ' AND l1_id = :l1_id';
                $params[':l1_id'] = $l1Id;
            }

            if ($l2Id !== null) {
                $sql .= ' AND l2_id = :l2_id';
                $params[':l2_id'] = $l2Id;
            }

            $sql .= ' ORDER BY id';

            $stmt = $this->db->prepare($sql);

            foreach ($params as $key => $value) {
                $paramType = is_int($value) ? PDO::PARAM_INT : PDO::FETCH_ASSOC;
                $stmt->bindValue($key, $value, $paramType);
            }

            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->log("Ошибка при получении категорий из таблицы {$table}: ".$e->getMessage(), true);

            return [];
        }
    }

    private function saveL1Categories(array $categories)
    {
        $stmt = $this->db->prepare('
            INSERT INTO categories_l1 (name, url)
            VALUES (:name, :url)
            ON DUPLICATE KEY UPDATE name = VALUES(name)
        ');

        foreach ($categories as $category) {
            $stmt->execute([
                ':name' => $category['name'],
                ':url' => $category['url'],
            ]);
        }
    }

    private function processL1Category(array $l1Category)
    {
        $this->log("Обработка категории L1: {$l1Category['name']}");

        try {
            $result = $this->makeRequest($l1Category['url']);
            if (! $result['success']) {
                throw new Exception('Не удалось загрузить категорию L1');
            }

            $document = new Document($result['response']);

            $l2Categories = $this->getL2Categories($document, $l1Category['id']);
            $this->saveL2Categories($l2Categories, $l1Category['id']);

            $l2ToProcess = $this->getAllCategories('categories_l2', $l1Category['id']);
            $this->log('Будет обработано категорий L2: '.count($l2ToProcess));

            foreach ($l2ToProcess as $l2Category) {
                $this->processL2Category($document, $l2Category, $l1Category);
            }

            $this->markCategoryAsProcessed('categories_l1', $l1Category['id'], true);
            $this->log("Категория L1 успешно обработана: {$l1Category['name']}");
        } catch (Exception $e) {
            $this->markCategoryAsProcessed('categories_l1', $l1Category['id'], false);
            $this->log("Ошибка обработки категории L1 {$l1Category['name']}: ".$e->getMessage(), true);
        }
    }

    private function getL2Categories(Document $document, int $l1Id): array
    {
        $categories = [];
        $categoryElements = $document->find('div.rubricListCategories div.category');

        foreach ($categoryElements as $element) {
            $link = $element->first('a.categoryInfo');
            if ($link) {
                $name = trim($link->first('span.categoryName')->text());
                $rubrShow = $link->attr('data-rubr-show');

                $url = $this->targetSite.'?rubr_show='.$rubrShow;

                $categories[] = [
                    'name' => $name,
                    'url' => $url,
                    'rubr_show' => $rubrShow,
                    'l1_id' => $l1Id,
                ];
            }
        }

        return $categories;
    }

    private function saveL2Categories(array $categories, int $l1Id)
    {
        $stmt = $this->db->prepare('
        INSERT INTO categories_l2 (name, url, rubr_show, l1_id)
        VALUES (:name, :url, :rubr_show, :l1_id)
        ON DUPLICATE KEY UPDATE 
            name = VALUES(name),
            url = VALUES(url)
    ');

        foreach ($categories as $category) {
            $stmt->execute([
                ':name' => $category['name'],
                ':url' => $category['url'],
                ':rubr_show' => $category['rubr_show'],
                ':l1_id' => $l1Id,
            ]);
        }
    }

    private function processL2Category(Document $document, array $l2Category, array $l1Category)
    {
        $this->log("Обработка категории L2: {$l2Category['name']}");

        try {
            if (! isset($l2Category['rubr_show'])) {
                $this->log('Ошибка: у категории L2 отсутствует rubr_show. Данные: '.print_r($l2Category, true));
                throw new Exception('Необходимое поле rubr_show отсутствует');
            }

            $visibleL3Categories = [];
            $categoryBlock = $document->first('div.rubricListCategories div.category');

            if ($categoryBlock) {
                $visibleLinks = $categoryBlock->find('a.categoryChild');
                foreach ($visibleLinks as $link) {
                    $name = trim(preg_replace('/\s*<span>.*<\/span>/', '', $link->innerHtml()));
                    $url = $this->normalizeUrl($link->attr('href'), $this->targetSite, false);

                    $visibleL3Categories[] = [
                        'name' => $name,
                        'url' => $url,
                        'l1_id' => $l1Category['id'],
                        'l2_id' => $l2Category['id'],
                    ];
                }

                $moreButton = $categoryBlock->first('a.categoryMore');
                $hiddenL3Categories = [];

                if ($moreButton) {
                    $this->log("Найдена кнопка 'Ещё' - загружаем скрытые категории L3");
                    $hiddenL3Categories = $this->getHiddenL3Categories($l2Category['rubr_show']);
                } else {
                    $this->log("Кнопка 'Ещё' не найдена - скрытые категории L3 отсутствуют");
                }
            }

            $allL3Categories = array_merge($visibleL3Categories, $hiddenL3Categories);
            $this->saveL3Categories($allL3Categories, $l1Category['id'], $l2Category['id']);

            $l3ToProcess = $this->getAllCategories('categories_l3', null, null, $l2Category['id']);
            $this->log('Будет обработано категорий L3: '.count($l3ToProcess));

            foreach ($l3ToProcess as $l3Category) {
                $this->processL3Category($l3Category, $l1Category, $l2Category);
            }

            $this->markCategoryAsProcessed('categories_l2', $l2Category['id'], true);
            $this->log("Категория L2 успешно обработана: {$l2Category['name']}");
        } catch (Exception $e) {
            $this->markCategoryAsProcessed('categories_l2', $l2Category['id'], false);
            $this->log("Ошибка обработки категории L2 {$l2Category['name']}: ".$e->getMessage(), true);
        }
    }

    private function getHiddenL3Categories(string $rubrShow): array
    {
        $url = "https://www.spr.ru/page/rubricList/?action=getRubrPopup&id_rubr_category={$rubrShow}&id_sprav=NaN&request_data_type=html&fe=1&ajax=1&get_popup=1&template_is_loaded=1";
        $result = $this->makeRequest($url);

        if (! $result['success']) {
            return [];
        }

        $document = new Document($result['response']);
        $categories = [];

        $links = $document->find('a.categoryChild');
        foreach ($links as $link) {
            $name = trim(preg_replace('/\s*<span>.*<\/span>/', '', $link->innerHtml()));
            $url = $this->normalizeUrl($link->attr('href'), $this->targetSite, false);

            $categories[] = [
                'name' => $name,
                'url' => $url,
            ];
        }

        return $categories;
    }

    private function saveL3Categories(array $categories, int $l1Id, int $l2Id)
    {
        $stmt = $this->db->prepare('
            INSERT INTO categories_l3 (name, url, l1_id, l2_id)
            VALUES (:name, :url, :l1_id, :l2_id)
            ON DUPLICATE KEY UPDATE name = VALUES(name)
        ');

        foreach ($categories as $category) {
            $stmt->execute([
                ':name' => $category['name'],
                ':url' => $category['url'],
                ':l1_id' => $l1Id,
                ':l2_id' => $l2Id,
            ]);
        }
    }

    private function processL3Category(array $l3Category, array $l1Category, array $l2Category)
    {
        $this->log("Обработка категории L3: {$l3Category['name']}");
        $currentCompanies = 0;

        try {

            $result = $this->makeRequest($l3Category['url']);
            if (! $result['success']) {
                throw new Exception('Не удалось загрузить категорию L3');
            }

            $document = new Document($result['response']);
            $companies = $this->getCompaniesFromPage($document, $l1Category['id'], $l2Category['id'], $l3Category['id']);

            $this->saveCompanies($companies);
            foreach ($companies as $company) {

                if (empty($company['id'])) {
                    $company['id'] = $this->getCompanyIdByUrl($company['url']);
                    if (empty($company['id'])) {
                        continue;
                    }
                }

                $this->parseCompanyDetails($company['url'], $company['id']);
                $currentCompanies++;
            }

            $this->markCategoryAsProcessed('categories_l3', $l3Category['id'], true);
        } catch (Exception $e) {
            $this->markCategoryAsProcessed('categories_l3', $l3Category['id'], false);
        }
    }

    private function getCompaniesFromPage(Document $document, int $l1Id, int $l2Id, int $l3Id): array
    {
        $companies = [];
        $companyElements = $document->find('section.moduleFirmsListWide div.item');

        foreach ($companyElements as $element) {
            $link = $element->first('a.itemTitle');
            if ($link) {
                $name = trim($link->text());
                $url = $this->normalizeUrl($link->attr('href'), $this->targetSite, false);

                $companies[] = [
                    'name' => $name,
                    'url' => $url,
                    'l1_id' => $l1Id,
                    'l2_id' => $l2Id,
                    'l3_id' => $l3Id,
                ];
            }
        }

        return $companies;
    }

    private function makePostRequest(string $url, array $postData): array
    {
        if (! $this->crawlDelayChecked) {
            $this->checkRobotsTxt($url);
            $this->crawlDelayChecked = true;
        }

        $proxy = $this->getRandomProxy();
        $delay = $this->getRandomizedDelay();

        if (! $this->useProxy || ! $proxy) {
            $delay *= 2;
        }

        $this->log('Задержка перед POST запросом: '.round($delay, 2).' сек');
        usleep($delay * 1000000);

        $result = $this->tryPostRequest($url, $postData, $proxy);

        if ($result['success']) {
            $this->cachePage($url, $result['response']);
        } elseif (! in_array($result['http_code'], [500, 502, 503, 504])) {
            if ($proxy) {
                $this->markProxyAsFailed($proxy);
            }
            $this->adjustDelay();
        }

        return $result;
    }

    private function saveCompanies(array &$companies)
    {
        $stmt = $this->db->prepare('
        INSERT INTO companies (
            name, url, 
            l1_id, l2_id, l3_id,
            company_name_for_path
        )
        VALUES (
            :name, :url, :l1_id, :l2_id, :l3_id,
            :company_name_for_path
        )
        ON DUPLICATE KEY UPDATE 
            name = VALUES(name),
            company_name_for_path = VALUES(company_name_for_path)
    ');

        foreach ($companies as &$company) {
            try {
                $stmt->execute([
                    ':name' => $company['name'],
                    ':url' => $company['url'],
                    ':l1_id' => $company['l1_id'],
                    ':l2_id' => $company['l2_id'],
                    ':l3_id' => $company['l3_id'],
                    ':company_name_for_path' => $this->sanitizeFilename($company['name']),
                ]);

                $companyId = $this->getCompanyIdByUrl($company['url']);

                if (! $companyId) {
                    throw new Exception('Не удалось получить ID после сохранения');
                }

                $company['id'] = $companyId;
            } catch (Exception $e) {
                $this->log("Ошибка сохранения компании {$company['name']}: ".$e->getMessage(), true);
                $company['id'] = null;
            }
        }
        unset($company);
    }

    private function getCompanyIdByUrl(string $url): ?int
    {
        $normalizedUrl = $this->normalizeUrlForComparison($url);

        $stmt = $this->db->prepare('
        SELECT id FROM companies 
        WHERE url = :url OR url = :normalizedUrl
        LIMIT 1
    ');

        $stmt->execute([
            ':url' => $url,
            ':normalizedUrl' => $normalizedUrl,
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? (int) $result['id'] : null;
    }

    private function normalizeUrlForComparison(string $url): string
    {
        $url = str_replace(['http://', 'https://', 'www.'], '', $url);
        $url = preg_replace('/\/+/', '/', $url);

        return rtrim($url, '/');
    }

    private function parseCompanyDetails(string $companyUrl, int $companyId)
    {
        $this->log("Парсинг деталей компании ID: {$companyId}");

        try {
            $result = $this->makeRequest($companyUrl);
            if (! $result['success']) {
                throw new Exception('Не удалось загрузить страницу компании');
            }

            $htmlSample = substr($result['response'], 0, 500);
            $this->log('HTML sample: '.$htmlSample);

            $document = new Document($result['response']);

            $address = $this->parseCompanyAddress($document);
            $this->log("Parsed address for {$companyUrl}: ".($address ?? 'NULL'));

            $reviews = $this->parseCompanyReviews($document);
            $this->log("Parsed reviews: good={$reviews['good']}, bad={$reviews['bad']}");

            $phoneImagePath = $this->parseCompanyPhone($document, $companyId);
            $this->log('Phone image path: '.($phoneImagePath ?? 'NULL'));

            $updated = $this->updateCompanyDetails($companyId, $address, $reviews, $phoneImagePath);

            if (! $updated) {
                $this->log("Не удалось обновить данные компании {$companyId}", true);
            } else {
                $this->log("Данные компании {$companyId} успешно обновлены");
            }
        } catch (Exception $e) {
            $this->log("Ошибка парсинга компании {$companyId}: ".$e->getMessage(), true);
        }
    }

    private function parseCompanyAddress(Document $document): ?string
    {
        $addressElement = $document->first('div.contactBox_right a.firm_link');

        if ($addressElement) {
            $address = trim(preg_replace('/\s+/', ' ', $addressElement->text()));

            if (! mb_check_encoding($address, 'UTF-8')) {
                $address = iconv('Windows-1251', 'UTF-8//IGNORE', $address);
                $this->log('Converted address encoding to UTF-8');
            }

            return ! empty($address) ? $address : 'empty';
        }

        return null;
    }

    private function parseCompanyReviews(Document $document): array
    {
        $reviews = ['good' => 0, 'bad' => 0];

        $goodReviewsElement = $document->first('div.firms_bspRight div.good_review');
        if ($goodReviewsElement) {
            $reviews['good'] = (int) trim($goodReviewsElement->text());
        }

        $badReviewsElement = $document->first('div.firms_bspRight div.bad_review');
        if ($badReviewsElement) {
            $reviews['bad'] = (int) trim($badReviewsElement->text());
        }

        return $reviews;
    }

    private function parseCompanyPhone(Document $document, int $companyId): ?string
    {
        $phoneContainer = $document->first('div.contactBox.box-adaptive div.contactBox_left.general_info div.contactBox_side__el.phone');

        if ($phoneContainer) {
            $mainPhoneImg = $phoneContainer->first('div.firstPhone img');
            if ($mainPhoneImg) {
                $imgSrc = $mainPhoneImg->attr('src');
                $phoneImagePath = $this->savePhoneImage($this->normalizeUrl($imgSrc, $this->targetSite, false), $companyId);

                return ! empty($phoneImagePath) ? $phoneImagePath : null;
            }
        }

        return null;
    }

    private function savePhoneImage(string $imageUrl, int $companyId): string
    {
        try {
            $names = $this->getCategoryNames($companyId);

            if (! $names) {
                $this->log("Не удалось получить названия категорий для компании ID: {$companyId}", true);

                return '';
            }

            $l1Name = $this->sanitizeFilename($names['l1_name']);
            $l2Name = $this->sanitizeFilename($names['l2_name']);
            $l3Name = $this->sanitizeFilename($names['l3_name']);
            $companyName = $this->sanitizeFilename($names['company_name']);

            $basePath = $this->imageBaseDir.
                $l1Name.'/'.
                $l2Name.'/'.
                $l3Name.'/'.
                $companyName.'_'.$companyId.'/';

            if (! file_exists($basePath)) {
                if (! mkdir($basePath, 0777, true) && ! is_dir($basePath)) {
                    $this->log("Не удалось создать директорию: {$basePath}", true);

                    return '';
                }
            }

            $filename = md5($imageUrl).'.svg';
            $fullPath = $basePath.$filename;

            $relativePath = 'company_images/'.
                $l1Name.'/'.
                $l2Name.'/'.
                $l3Name.'/'.
                $companyName.'_'.$companyId.'/'.$filename;

            if ($this->downloadImage($imageUrl, $fullPath)) {
                return $relativePath;
            }

            $this->log("Не удалось загрузить изображение телефона для компании {$companyId}", true);

            return '';
        } catch (Exception $e) {
            $this->log('Ошибка при сохранении изображения телефона: '.$e->getMessage(), true);

            return '';
        }
    }

    private function getCategoryNames(int $companyId): array
    {
        $stmt = $this->db->prepare('
        SELECT 
            l1.name as l1_name,
            l2.name as l2_name, 
            l3.name as l3_name,
            c.name as company_name
        FROM companies c
        JOIN categories_l1 l1 ON c.l1_id = l1.id
        JOIN categories_l2 l2 ON c.l2_id = l2.id  
        JOIN categories_l3 l3 ON c.l3_id = l3.id
        WHERE c.id = ?
    ');

        $stmt->execute([$companyId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function sanitizeFilename(string $name): string
    {
        $name = preg_replace('/[^\w\-\.]/u', '_', $name);
        $name = preg_replace('/_+/', '_', $name);

        return mb_substr(trim($name, '_'), 0, 50);
    }

    private function downloadImage(string $url, string $savePath): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
        ]);

        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200) {
            return file_put_contents($savePath, $imageData) !== false;
        }

        return false;
    }

    private function updateCompanyDetails(int $companyId, ?string $address, array $reviews, ?string $phoneImagePath): bool
    {

        try {
            $this->log("Updating company {$companyId} with address: ".($address ?? 'NULL'));

            $stmt = $this->db->prepare('
            UPDATE companies 
            SET address = :address, 
                good_reviews = :good_reviews, 
                bad_reviews = :bad_reviews,
                phone_image_path = :phone_image_path
            WHERE id = :id
        ');

            $params = [
                ':address' => $address,
                ':good_reviews' => $reviews['good'],
                ':bad_reviews' => $reviews['bad'],
                ':phone_image_path' => $phoneImagePath,
                ':id' => $companyId,
            ];

            $this->log('SQL params: '.print_r($params, true));

            $result = $stmt->execute($params);

            if (! $result) {
                $errorInfo = $stmt->errorInfo();
                $this->log('SQL error: '.print_r($errorInfo, true), true);

                return false;
            }

            $rowCount = $stmt->rowCount();
            $this->log("Updated rows: {$rowCount}");

            return $rowCount > 0;
        } catch (PDOException $e) {
            $this->log("Ошибка при обновлении компании {$companyId}: ".$e->getMessage(), true);

            return false;
        }
    }

    private function markCategoryAsProcessed(string $table, int $id, bool $isProcessed)
    {
        $stmt = $this->db->prepare("
            UPDATE {$table}
            SET processed = :processed
            WHERE id = :id
        ");

        $stmt->execute([
            ':id' => $id,
            ':processed' => $isProcessed ? 1 : 0,
        ]);
    }

    private function normalizeUrl($path, $baseUrl, $isHtml = true)
    {
        $normalize_url_internal = function ($url, $baseUrl) {

            if (strpos($url, '//') === 0) {
                $baseParts = parse_url($baseUrl);
                $scheme = $baseParts['scheme'] ?? 'http';

                return $scheme.':'.$url;
            }

            if (preg_match('#^https?://#i', $url)) {
                return $url;
            }

            $baseParts = parse_url($baseUrl);
            $scheme = $baseParts['scheme'] ?? 'http';
            $host = $baseParts['host'] ?? '';

            if (strpos($url, '/') === 0) {
                return $scheme.'://'.$host.$url;
            }

            $basePath = $baseParts['path'] ?? '/';
            $isBaseFile = false;
            $basePathTrimmed = rtrim($basePath, '/');

            if ($basePathTrimmed !== '' && $basePathTrimmed !== '/') {
                $lastSegment = basename($basePathTrimmed);
                if (strpos($lastSegment, '.') !== false && $lastSegment !== '.' && $lastSegment !== '..') {
                    $isBaseFile = true;
                }
            }

            if ($isBaseFile) {
                $baseDir = dirname($basePath === '/' ? '/dummy' : $basePath);
                $baseDir = ($baseDir === '.') ? '/' : $baseDir.'/';
            } else {
                $baseDir = $basePath;
                if (substr($baseDir, -1) !== '/') {
                    $baseDir .= '/';
                }
            }

            $fullPath = $baseDir.$url;
            $path = str_replace('\\', '/', $fullPath);
            $parts = explode('/', $path);
            $result = [];

            foreach ($parts as $part) {
                if ($part === '' || $part === '.') {
                    continue;
                } elseif ($part === '..') {
                    if (! empty($result)) {
                        array_pop($result);
                    }
                } else {
                    $result[] = $part;
                }
            }

            $normalized = implode('/', $result);
            $wasDirectory = substr($path, -1) === '/';
            $normalized = '/'.ltrim($normalized, '/');

            if ($wasDirectory && substr($normalized, -1) !== '/') {
                $normalized .= '/';
            }

            return $scheme.'://'.$host.$normalized;
        };

        if ($isHtml) {
            return preg_replace_callback(
                '#(href|src)=["\'](.+?)["\']#i',
                function ($matches) use ($baseUrl, $normalize_url_internal) {
                    $url = $matches[2];
                    $normalized = $normalize_url_internal($url, $baseUrl);

                    return $matches[1].'="'.$normalized.'"';
                },
                $path
            );
        }

        return $normalize_url_internal($path, $baseUrl);
    }

    private function log(string $message, bool $isError = false)
    {
        if (! mb_check_encoding($message, 'UTF-8')) {
            $message = iconv('Windows-1251', 'UTF-8//IGNORE', $message);
        }

        $message = preg_replace('/[\x00-\x1F\x7F]/u', '', $message);

        try {
            $stmt = $this->db->prepare('
            INSERT INTO parser_logs (message, is_error)
            VALUES (:message, :is_error)
        ');

            $stmt->execute([
                ':message' => $message,
                ':is_error' => $isError ? 1 : 0,
            ]);
        } catch (PDOException $e) {
            error_log('Failed to write log: '.$e->getMessage());
        }
    }

    public function makeRequest($url, $skipCacheCheck = false)
    {
        if (! $this->crawlDelayChecked) {
            $this->checkRobotsTxt($url);
            $this->crawlDelayChecked = true;
        }

        if (! $skipCacheCheck) {
            $cachedContent = $this->getCachedPage($url);
            if ($cachedContent !== false) {
                $this->log("Используем закешированную версию страницы: {$url}");

                return [
                    'success' => true,
                    'response' => $cachedContent,
                    'from_cache' => true,
                ];
            }
        }

        $proxy = $this->getRandomProxy();
        $delay = $this->getRandomizedDelay();

        if (! $this->useProxy || ! $proxy) {
            $delay *= 2;
        }

        $this->log('Задержка перед запросом: '.round($delay, 2).' сек');
        usleep((int) round($delay * 1000000));

        $result = $this->tryRequest($url, $proxy);

        if ($result['success']) {
            $this->cachePage($url, $result['response']);
        } elseif (! in_array($result['http_code'], [500, 502, 503, 504])) {
            if ($proxy) {
                $this->markProxyAsFailed($proxy);
            }
            $this->adjustDelay();
        }

        return $result;
    }

    private function tryPostRequest(string $url, array $postData, $proxy): array
    {
        $ch = curl_init();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => [
                'X-Requested-With: XMLHttpRequest',
                'Accept: text/html, */*; q=0.01',
            ],
        ];

        if ($proxy) {
            $options[CURLOPT_PROXY] = $proxy['ip_port'];
            $options[CURLOPT_PROXYUSERPWD] = "{$proxy['login']}:{$proxy['password']}";
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $isBanned = $httpCode == 403 || $httpCode == 429;
        $hasCurlError = ! empty($error);

        if ($hasCurlError) {
            $this->log("Ошибка cURL: $error", true);
        }

        $isBannedOrError = $isBanned || $hasCurlError;

        return [
            'success' => ! $isBannedOrError,
            'response' => $response,
            'proxy' => $proxy,
            'http_code' => $httpCode,
            'error' => $error,
        ];
    }

    private function getRandomProxy()
    {
        if (! $this->useProxy || empty($this->proxies)) {
            return false;
        }

        $activeProxies = array_diff_key($this->proxies, $this->failedProxies);

        if (empty($activeProxies)) {
            $this->log('Все прокси забанены. Переключаемся на прямой режим.');
            $this->useProxy = false;
            $this->currentDelay = $this->directDelay;
            $this->lastUsedProxyId = null;

            return false;
        }

        if (count($activeProxies) === 1 && isset($activeProxies[$this->lastUsedProxyId])) {
            return $activeProxies[$this->lastUsedProxyId];
        }

        $availableProxies = array_filter($activeProxies, function ($proxy) {
            return $proxy['id'] !== $this->lastUsedProxyId;
        });

        if (empty($availableProxies)) {
            $availableProxies = $activeProxies;
        }

        $randomKey = array_rand($availableProxies);

        return $availableProxies[$randomKey];
    }

    private function tryRequest($url, $proxy)
    {
        $ch = curl_init();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => 'windows-1251',
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => $this->userAgent,
        ];

        if ($proxy) {
            $options[CURLOPT_PROXY] = $proxy['ip_port'];
            $options[CURLOPT_PROXYUSERPWD] = "{$proxy['login']}:{$proxy['password']}";
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (! mb_check_encoding($response, 'UTF-8')) {
            $response = iconv('Windows-1251', 'UTF-8//IGNORE', $response);
        }

        $isBanned = $httpCode == 403 || $httpCode == 429;
        $hasCurlError = ! empty($error);

        if ($hasCurlError) {
            $this->log("Ошибка cURL: $error", true);
        }

        $isBannedOrError = $isBanned || $hasCurlError;

        return [
            'success' => ! $isBannedOrError,
            'response' => $response,
            'proxy' => $proxy,
            'http_code' => $httpCode,
            'error' => $error,
        ];
    }

    private function checkRobotsTxt($url)
    {
        $robotsUrl = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).'/robots.txt';

        try {
            $result = $this->tryRequest($robotsUrl, $this->getRandomProxy());

            if ($result['success'] && preg_match('/Crawl-delay:\s*(\d+)/i', $result['response'], $matches)) {
                $this->baseDelay = max($this->baseDelay, (int) $matches[1]);
                $this->currentDelay = $this->baseDelay;
                $this->log("Установлена задержка из robots.txt: {$this->baseDelay} сек");
            }
        } catch (Exception $e) {
            $this->log("Ошибка получения задержки из robots.txt. Задержка не изменена и составляет: {$this->baseDelay}");
        }
    }

    private function markProxyAsFailed($proxy)
    {
        $this->failedProxies[$proxy['id']] = time();
        $this->log("Прокси {$proxy['ip_port']} забанен и добавлен в черный список");
    }

    private function adjustDelay()
    {
        $numberOfTotalProxies = count($this->proxies);
        $numberOfFailedProxies = count($this->failedProxies);

        if ($numberOfFailedProxies > 0) {
            $ratio = $numberOfFailedProxies / $numberOfTotalProxies;
            $this->currentDelay = $this->baseDelay * (1 + $ratio * $this->delayGrowthFactor);
            $this->currentDelay = min($this->currentDelay, $this->maxAllowedDelay);

            $this->log(sprintf(
                'Задержка: %.1f сек (коэф. роста: %d, забанено %d/%d прокси)',
                $this->currentDelay,
                $this->delayGrowthFactor,
                $numberOfFailedProxies,
                $numberOfTotalProxies
            ));
        }
    }

    private function getRandomizedDelay(): float
    {
        $maxDelay = $this->currentDelay * 1.2;

        return $this->currentDelay + (mt_rand() / mt_getrandmax()) * ($maxDelay - $this->currentDelay);
    }

    private function getCachedPage(string $url)
    {
        $stmt = $this->db->prepare('SELECT html_content FROM page_cache WHERE url = :url');
        $stmt->execute([':url' => $url]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? $result['html_content'] : false;
    }

    private function cachePage(string $url, string $html)
    {

        if (! mb_check_encoding($html, 'UTF-8')) {
            $html = iconv('Windows-1251', 'UTF-8//IGNORE', $html);
        }

        $stmt = $this->db->prepare('
            INSERT INTO page_cache (url, html_content) 
            VALUES (:url, :html)
            ON DUPLICATE KEY UPDATE html_content = VALUES(html_content)
        ');

        $stmt->execute([
            ':url' => $url,
            ':html' => $html,
        ]);
    }
}

$proxies = [
    // [
    //     'id' => 'proxy1',
    //     'ip:port:login:password' => ',
    // ],
    // [
    //     'id' => 'proxy2',
    //     'ip:port:login:password' => '',

];

try {
    $parser = new SprRuParser($proxies);
    $parser->run();
} catch (Exception $e) {
    echo 'Ошибка: '.$e->getMessage();
}
