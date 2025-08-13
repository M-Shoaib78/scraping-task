<?php
require 'function.php';

libxml_use_internal_errors(true);

function buildListUrl(string $agency, int $page): string {
    $query = http_build_query([
        'agency' => $agency,
        'page' => $page,
        'sort' => 'PostingDate',
        'isDescendingSort' => 'true',
    ]);
    return "https://www.schooljobs.com/careers/home/index?{$query}";
}

function http_get_with_curl(string $url, ?string $referer = null, $cookieFile = null): string {
    static $ch = null;
    if ($ch === null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_ENCODING, '');
    }
    

    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'X-Requested-With: XMLHttpRequest',
        'Sec-Fetch-Dest: empty',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Site: same-origin',
    ];

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

    if ($referer) {
        curl_setopt($ch, CURLOPT_REFERER, $referer);
    }

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    $response = curl_exec($ch);
    if ($response === false) {
        throw new RuntimeException('cURL error: ' . curl_error($ch));
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("HTTP {$status} for URL: {$url}");
    }
    return $response;
}

function parse_listings_html(string $html): array {
    $doc = new DOMDocument();
    $htmlUtf8 = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
    $doc->loadHTML($htmlUtf8);
    $xpath = new DOMXPath($doc);

    $items = [];
    $liNodes = $xpath->query("//li[contains(concat(' ', normalize-space(@class), ' '), ' list-item ')]");
    if (!$liNodes || $liNodes->length === 0) {
        return $items;
    }

    foreach ($liNodes as $li) {
        $jobId = trim($li->getAttribute('data-job-id'));

        $aNode = $xpath->query(".//h3[contains(@class,'job-item-link-container')]/a", $li)->item(0);
        $title = $aNode ? trim(html_entity_decode($aNode->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        $href = $aNode ? trim($aNode->getAttribute('href')) : '';
        if ($href !== '' && strpos($href, 'http') !== 0) {
            $href = 'https://www.schooljobs.com' . $href;
        }

        $metaLis = $xpath->query(".//ul[contains(@class,'list-meta')]/li", $li);
        $location = '';
        $jobType = '';
        $category = '';

        if ($metaLis && $metaLis->length > 0) {
            $location = trim(preg_replace('/\s+/', ' ', $metaLis->item(0)->textContent));
        }
        if ($metaLis && $metaLis->length > 1) {
            $raw = trim(preg_replace('/\s+/', ' ', $metaLis->item(1)->textContent));
            // Typically like: "Full-Time - $80,000.00 - $85,000.00 Annually"
            $jobType = trim(explode('-', $raw, 2)[0]);
        }
        $catNode = $xpath->query(".//ul[contains(@class,'list-meta')]/li[contains(@class,'categories-list')]", $li)->item(0);
        if ($catNode) {
            $catText = trim(preg_replace('/\s+/', ' ', $catNode->textContent));
            $category = trim(preg_replace('/^Category:\s*/i', '', $catText));
        }

        $descNode = $xpath->query(".//div[contains(@class,'list-entry')]", $li)->item(0);
        $description = $descNode ? trim(preg_replace('/\s+/', ' ', html_entity_decode($descNode->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) : '';

        $postedNode = $xpath->query(".//div[contains(@class,'list-published')]", $li)->item(0);
        $posted = $postedNode ? trim(preg_replace('/\s+/', ' ', $postedNode->textContent)) : '';
        // Example: "Posted 5 days ago" – keep as-is

        $items[] = [
            'job_id' => $jobId,
            'title' => $title,
            'location' => $location,
            'job_type' => $jobType,
            'category' => $category,
            'description' => $description,
            'application_link' => $href,
            'posting_date' => $posted,
        ];
    }

    return $items;
}

function scrape_rideru(string $inputUrl, int $maxPages = 3): array {
    $parsed = parse_url($inputUrl);
    $pathSegments = explode('/', trim($parsed['path'] ?? '', '/'));
    $agency = $pathSegments[1] ?? null;
    if (!$agency) {
        throw new InvalidArgumentException('Agency not found in URL');
    }
    
    $page = 1;
    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $queryParams);
        if (isset($queryParams['page'])) {
            $page = max(1, (int)$queryParams['page']);
        }
    }
    
    $cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'schooljobs_rideru_cookie.txt';
    $results = [];
    
    // Iterate pages 1..$maxPages
    for ($p = $page; $p < $page + $maxPages; $p++) {
        $listUrl = buildListUrl($agency, $p);
        $referer = "https://www.schooljobs.com/careers/{$agency}?page={$p}";
        $html = http_get_with_curl($listUrl, $referer, $cookieFile);

        $items = parse_listings_html($html);
        if (empty($items)) {
            // Stop early if a page yields no results
            break;
        }
        $results = array_merge($results, $items);
    }

    return $results;
}

// Run
try {
    $inputUrl = "https://www.schooljobs.com/careers/rideru?page=1";
    $jobs = scrape_rideru($inputUrl, 3);

    // Output JSON to verify
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'count' => count($jobs),
        'jobs' => $jobs,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Example insert (uncomment and adapt as needed)
    /*
    // $pdo = ... obtain PDO handle
    $stmt = $pdo->prepare("
        INSERT INTO jobs (
            job_id, title, location, job_type, category, description, application_link, posting_date
        ) VALUES (
            :job_id, :title, :location, :job_type, :category, :description, :application_link, :posting_date
        )
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            location = VALUES(location),
            job_type = VALUES(job_type),
            category = VALUES(category),
            description = VALUES(description),
            application_link = VALUES(application_link),
            posting_date = VALUES(posting_date)
    ");
    foreach ($jobs as $row) {
        $stmt->execute($row);
    }
    */
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error: " . $e->getMessage();
}

