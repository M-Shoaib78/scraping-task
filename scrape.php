<?php
function dd($data)
{
    echo "<pre>";
    print_r($data);
    echo "</pre>";
    die;
}

function getJobs($agency, $page = 1)
{
    $url = "https://www.schooljobs.com/careers/home/index?agency={$agency}&page={$page}&sort=PostingDate&isDescendingSort=true";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8'
    ]);

    $response = curl_exec($ch);
    curl_close($ch);
    dd($response);

    if (!$response) {
        die("No response received.");
    }

    // Now parse HTML response
    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // suppress HTML5 warnings
    $dom->loadHTML($response);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $jobListings = [];

    // Loop through each job listing
    $listItems = $xpath->query('//ul[contains(@class, "search-results-listing-container")]/li');
    dd($listItems);
    foreach ($listItems as $item) {
        $jobId = $item->getAttribute('data-job-id');

        // Job Title and Application Link
        $linkNode = $xpath->query('.//a[contains(@class, "item-details-link")]', $item)->item(0);
        $title = trim($linkNode->nodeValue ?? '');
        $relativeLink = $linkNode->getAttribute('href');
        $applicationLink = "https://www.schooljobs.com" . $relativeLink;

        // Job Description
        $descNode = $xpath->query('.//div[contains(@class, "list-entry")]', $item)->item(0);
        $description = trim($descNode->nodeValue ?? '');

        // Job Location
        $locationNode = $xpath->query('.//ul[contains(@class, "list-meta")]/li[1]', $item)->item(0);
        $location = trim($locationNode->nodeValue ?? '');

        // Job Type and Salary (combined)
        $jobTypeNode = $xpath->query('.//ul[contains(@class, "list-meta")]/li[2]', $item)->item(0);
        $jobTypeRaw = trim($jobTypeNode->nodeValue ?? '');
        preg_match('/(Full-Time|Part-Time)/i', $jobTypeRaw, $typeMatch);
        $jobType = $typeMatch[0] ?? '';

        // Category
        $categoryNode = $xpath->query('.//li[contains(@class, "categories-list")]', $item)->item(0);
        $category = trim(str_replace('Category:', '', $categoryNode->nodeValue ?? ''));

        // Posting Date
        $dateNode = $xpath->query('.//div[contains(@class, "list-published")]//span', $item)->item(0);
        $postingDate = trim($dateNode->nodeValue ?? '');

        $jobListings[] = [
            'title' => $title,
            'location' => $location,
            'description' => $description,
            'link' => $applicationLink,
            'date' => $postingDate,
            'category' => $category,
            'type' => $jobType
        ];
    }

    return $jobListings;
}

// Call the function for page 1 (you can loop over 3 pages if needed)
$agency = 'rideru';
$jobs = getJobs($agency, 1);
dd($jobs);
