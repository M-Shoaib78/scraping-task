<?php
require "db.php";

function dd($data)
{
    echo "<pre>";
    var_dump($data);
    echo "</pre>";
    die;
}


function fetchJobPageHtml(string $agency, int $page = 1): string
{
    $url = "https://www.schooljobs.com/careers/home/index?agency={$agency}&page={$page}&sort=PostingDate&isDescendingSort=true";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'X-Requested-With: XMLHttpRequest',
        ]
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        die("Failed to fetch jobs from page {$page}.");
    }

    return $response;
}

function parseJobsFromHtml(string $html): array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $jobs = [];

    $listItems = $xpath->query('//ul[contains(@class, "search-results-listing-container")]/li');

    foreach ($listItems as $item) {
        $linkNode = $xpath->query('.//a[contains(@class, "item-details-link")]', $item)->item(0);
        $title = trim($linkNode->nodeValue ?? '');
        $relativeLink = $linkNode->getAttribute('href');
        $applicationLink = "https://www.schooljobs.com" . $relativeLink;

        $descNode = $xpath->query('.//div[contains(@class, "list-entry")]', $item)->item(0);
        $description = trim($descNode->nodeValue ?? '');

        $locationNode = $xpath->query('.//ul[contains(@class, "list-meta")]/li[1]', $item)->item(0);
        $location = trim($locationNode->nodeValue ?? '');

        $jobTypeNode = $xpath->query('.//ul[contains(@class, "list-meta")]/li[2]', $item)->item(0);
        $jobTypeRaw = trim($jobTypeNode->nodeValue ?? '');
        preg_match('/(Full-Time|Part-Time)/i', $jobTypeRaw, $typeMatch);
        $jobType = $typeMatch[0] ?? '';

        $categoryNode = $xpath->query('.//li[contains(@class, "categories-list")]', $item)->item(0);
        $category = trim(str_replace('Category:', '', $categoryNode->nodeValue ?? ''));

        $dateNode = $xpath->query('.//div[contains(@class, "list-published")]//span', $item)->item(0);
        $postingDate = trim($dateNode->nodeValue ?? '');

        $jobs[] = compact('title', 'location', 'description', 'applicationLink', 'postingDate', 'category', 'jobType');
    }

    return $jobs;
}

function storeJobsInDatabase(array $jobs, PDO $pdo)
{
    $stmt = $pdo->prepare("
        INSERT INTO jobs (title, location, description, link, date, category, type)
        VALUES (:title, :location, :description, :link, :date, :category, :type)
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            location = VALUES(location),
            description = VALUES(description),
            date = VALUES(date),
            category = VALUES(category),
            type = VALUES(type)
    ");

    foreach ($jobs as $job) {
        $stmt->execute([
            ':title' => $job['title'],
            ':location' => $job['location'],
            ':description' => $job['description'],
            ':link' => $job['applicationLink'],
            ':date' => $job['postingDate'],
            ':category' => $job['category'],
            ':type' => $job['jobType']
        ]);
    }
}

function scrapeAndStoreJobs(string $agency, int $maxPages, PDO $pdo)
{
    $allJobs = [];

    for ($page = 1; $page <= $maxPages; $page++) {
        $html = fetchJobPageHtml($agency, $page);
        $jobs = parseJobsFromHtml($html);
        $allJobs = array_merge($allJobs, $jobs);
    }
    dd($allJobs);//remove this line to make the code work
    // storeJobsInDatabase($allJobs, $pdo);//uncomment this line to store in db 
}


scrapeAndStoreJobs('rideru', 3, $pdo);

