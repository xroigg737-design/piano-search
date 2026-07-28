<?php
set_time_limit(300);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$model  = trim($_GET['model'] ?? '');
$region = trim($_GET['region'] ?? 'espanya');
$fresh  = !empty($_GET['fresh']);
$scrapersRun = [];

if ($model === '') {
    die(json_encode(['error' => 'Cal especificar un model', 'results' => []]));
}

// Ensure "yamaha" is in the search query for store searches
$searchModel = $model;
if (!preg_match('/yamaha/i', $model)) {
    $searchModel = 'yamaha ' . $model;
}

// ── Cache (1h) ──────────────────────────────────────────────
$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
$cacheKey  = md5(mb_strtolower($model) . $region);
$cacheFile = "$cacheDir/$cacheKey.json";

if (!$fresh && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    $cached['cached'] = true;
    $cached['cached_at'] = date('c', filemtime($cacheFile));
    echo json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── Helpers ─────────────────────────────────────────────────
function scraper(string $name): void {
    global $scrapersRun, $results;
    $scrapersRun[$name] = ['status' => 'running', 'start' => microtime(true), 'count_before' => count($results)];
}

function scraperDone(string $name): void {
    global $scrapersRun, $results;
    $elapsed = isset($scrapersRun[$name]['start']) ? round(microtime(true) - $scrapersRun[$name]['start'], 1) : 0;
    $countBefore = $scrapersRun[$name]['count_before'] ?? 0;
    $scrapersRun[$name] = ['status' => 'ok', 'found' => count($results) - $countBefore, 'time' => $elapsed];
}

function scraperFail(string $name): void {
    global $scrapersRun;
    $elapsed = isset($scrapersRun[$name]['start']) ? round(microtime(true) - $scrapersRun[$name]['start'], 1) : 0;
    $scrapersRun[$name] = ['status' => 'error', 'time' => $elapsed];
}

function fetch(string $url, int $timeout = 10): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: es-ES,es;q=0.9,ca;q=0.8,en;q=0.7,de;q=0.5',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 400 && $body) ? $body : null;
}

function fetchWithCode(string $url, int $timeout = 10): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: ja,en;q=0.9,es;q=0.8',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'code' => $code];
}

function clean(string $s): string {
    return trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($s, ENT_QUOTES|ENT_HTML5, 'UTF-8'))));
}

function extractYear(string $text, string $title = ''): string {
    $r = extractYearEx($text, $title);
    return $r['year'];
}

function extractYearEx(string $text, string $title = ''): array {
    // 1) Explicit year near keywords → exact
    if (preg_match('/(?:año|any|fabricaci|built|baujahr|bouwjaar|from|de)\s*:?\s*(19[6-9]\d|20[0-2]\d)/i', $text, $m)) {
        return ['year' => $m[1], 'confidence' => 'exact'];
    }
    // 2) Year in parentheses like "(1995)" or "(2007)" → exact
    if (preg_match('/\(\s*(19[6-9]\d|20[0-2]\d)\s*\)/', $text, $m)) {
        return ['year' => $m[1], 'confidence' => 'exact'];
    }
    // 3) Serial number → serial
    $serial = extractSerial($text);
    if ($serial) {
        $year = serialToYear($serial, $title);
        if ($year) return ['year' => $year, 'confidence' => 'serial'];
    }
    // 4) Standalone year (less certain without context keyword) → exact but weaker
    if (preg_match('/\b(19[6-9]\d|20[0-2]\d)(?=[^0-9]|$)/', $text, $m)) {
        return ['year' => $m[1], 'confidence' => 'exact'];
    }
    // 5) Partial serial prefix like "nº 632..." or "serie 5xx" → estimated
    if (preg_match('/(?:serial|serie|s\/n|n[ºo°])\s*:?\s*[a-z]?(\d{3,4})/i', $text, $m)) {
        $prefix = $m[1];
        $estYear = estimateYearFromPrefix($prefix, $title);
        if ($estYear) return ['year' => $estYear, 'confidence' => 'estimated'];
    }
    // 6) Textual serial range: "superior a X millones", "mayor de X millones", "above X million"
    if (preg_match('/(?:superior|mayor|m[aá]s|above|over|mehr\s+als|encima|por\s+encima)\s+(?:a|de|que|than|los)?\s*(\d+)\s*(?:mill|mili)/i', $text, $m)) {
        $millions = (int) $m[1];
        $prefix = $millions * 1000;
        $estYear = estimateYearFromPrefix((string) $prefix, $title);
        if ($estYear) return ['year' => '>' . $estYear, 'confidence' => 'estimated'];
    }
    return ['year' => '', 'confidence' => ''];
}

function estimateYearFromPrefix(string $prefix, string $title = ''): ?string {
    $n = (int) $prefix;
    $isGrand = (bool) preg_match('/\b[CGS]\d/i', $title);
    // Map 3-4 digit prefixes to approximate Hamamatsu years
    $milestones = [
        124 => 1960, 149 => 1961, 188 => 1962, 237 => 1963, 298 => 1964,
        368 => 1965, 489 => 1966, 570 => 1967, 685 => 1968, 805 => 1969,
        960 => 1970, 1130 => 1971, 1317 => 1972, 1510 => 1973, 1745 => 1974,
        1945 => 1975, 2154 => 1976, 2384 => 1977, 2585 => 1978, 2810 => 1979,
        3001 => 1980, 3261 => 1981, 3465 => 1982, 3646 => 1983, 3832 => 1984,
        3987 => 1985, 4156 => 1986, 4334 => 1987, 4491 => 1988, 4672 => 1989,
        4837 => 1990, 4967 => 1991, 5086 => 1992, 5204 => 1993, 5296 => 1994,
        5375 => 1995, 5446 => 1996, 5530 => 1997, 5579 => 1998, 5792 => 1999,
        5860 => 2000, 5920 => 2001, 5970 => 2002, 6020 => 2003, 6060 => 2004,
        6100 => 2005, 6145 => 2006, 6191 => 2007, 6220 => 2008, 6250 => 2009,
        6280 => 2010, 6310 => 2011, 6340 => 2012, 6360 => 2013, 6380 => 2014,
        6400 => 2015, 6420 => 2016, 6440 => 2017, 6460 => 2018, 6480 => 2019,
        6500 => 2020, 6520 => 2021,
    ];
    // If 3 digits, it's thousands (e.g., "632" = 6320000 range → ~2012)
    if (strlen($prefix) === 3) $n *= 10;
    $result = null;
    foreach ($milestones as $start => $year) {
        if ($n >= $start) $result = (string) $year;
        else break;
    }
    return $result;
}

function extractYearFromPage(string $html, string $title): array {
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    // 1) Serial number → serial
    $serial = extractSerial($text);
    if ($serial) {
        $year = serialToYear($serial, $title);
        if ($year) return ['year' => $year, 'confidence' => 'serial'];
    }
    // 2) Year near keywords → exact
    if (preg_match('/(?:año|any|fabricaci|built|baujahr|bouwjaar)\s*:?\s*(19[6-9]\d|20[0-2]\d)/i', $text, $m)) {
        return ['year' => $m[1], 'confidence' => 'exact'];
    }
    // 3) Partial serial prefix → estimated
    if (preg_match('/(?:serial|serie|s\/n|n[ºo°])\s*:?\s*[a-z]?(\d{3,4})/i', $text, $m)) {
        $estYear = estimateYearFromPrefix($m[1], $title);
        if ($estYear) return ['year' => $estYear, 'confidence' => 'estimated'];
    }
    // 4) Textual serial range: "superior a X millones", "mayor de X millones"
    if (preg_match('/(?:superior|mayor|m[aá]s|above|over|mehr\s+als|encima|por\s+encima)\s+(?:a|de|que|than|los)?\s*(\d+)\s*(?:mill|mili)/i', $text, $m)) {
        $millions = (int) $m[1];
        $prefix = $millions * 1000;
        $estYear = estimateYearFromPrefix((string) $prefix, $title);
        if ($estYear) return ['year' => '>' . $estYear, 'confidence' => 'estimated'];
    }
    return ['year' => '', 'confidence' => ''];
}

function extractSerial(string $text): ?string {
    // "Serial: 1234567", "Nº serie: 1234567", "S/N: 1234567", "serienummer X1234567"
    // Also handle dot/comma thousands: "serie 5.400.000", "serie 1,317,500"
    if (preg_match('/(?:serial|serienummer|serie|s\/n|n[ºo°]\s*(?:de\s+)?serie)[:\s]*[a-z]?(\d{1,3}(?:[.,]\d{3}){1,2})/i', $text, $m)) {
        $num = str_replace(['.', ',', ' '], '', $m[1]);
        if (strlen($num) >= 5 && strlen($num) <= 7) {
            $n = (int)$num;
            if ($n >= 1700 && $n <= 6600000) return $num;
        }
    }
    if (preg_match('/(?:serial|serienummer|serie|s\/n|n[ºo°]\s*(?:de\s+)?serie)[:\s]*[a-z]?(\d{5,7})/i', $text, $m)) {
        return trim($m[1]);
    }
    // Prefixed serials: H0306726, T283503, J3400000, U150000, YT281000
    if (preg_match('/\b(H\d{7}|T\d{6}|J\d{7,8}|U\d{6}|YT\d{6})\b/i', $text, $m)) {
        return strtoupper($m[1]);
    }
    // Standalone 7-digit numbers not formatted as prices (no dots/commas inside)
    if (preg_match('/(?<![.,\d])(\d{7})(?![.,\d])/', $text, $m)) {
        $n = (int)$m[1];
        if ($n >= 1700 && $n <= 6600000) return $m[1];
    }
    // Standalone dot-separated numbers that look like serials: "5.400.000", "1.317.500"
    if (preg_match('/\b(\d{1,2}\.\d{3}\.\d{3})\b/', $text, $m)) {
        $num = str_replace('.', '', $m[1]);
        $n = (int)$num;
        if ($n >= 100000 && $n <= 6600000) return $num;
    }
    return null;
}

function serialToYear(string $serial, string $title = ''): ?string {
    $serial = strtoupper($serial);

    // Hangzhou, China (H prefix)
    if (preg_match('/^H(\d{7})$/', $serial, $m)) {
        $n = (int)$m[1];
        $china = [
            2004=>4000, 2005=>4900, 2006=>10900, 2007=>20700, 2008=>39900,
            2009=>71498, 2010=>105429, 2011=>150753, 2012=>201988, 2013=>257154,
            2014=>306726, 2015=>359873, 2016=>414970, 2017=>471933, 2018=>535799,
            2019=>604133, 2020=>673783, 2021=>727175,
        ];
        return serialLookup($n, $china);
    }

    // Thomaston, Georgia (T prefix)
    if (preg_match('/^T(\d{6})$/', $serial, $m)) {
        $n = (int)$m[1];
        $thomaston = [
            1983=>500101, 1984=>500422, 1985=>500998, 1986=>502874,
            1987=>101856, 1988=>110501, 1989=>122421, 1990=>132706,
            1991=>143101, 1992=>155131, 1993=>167386, 1994=>177711,
            1995=>189741, 1996=>202945, 1997=>212917, 1998=>224053,
            1999=>237164, 2000=>251146, 2001=>265755, 2002=>275258,
            2003=>283503, 2004=>294877,
        ];
        // T500xxx (1983-1986) then T1xxxxx (1987+)
        if ($n >= 500000) {
            foreach ([1986=>502874,1985=>500998,1984=>500422,1983=>500101] as $y=>$s) {
                if ($n >= $s) return (string)$y;
            }
        }
        $late = array_filter($thomaston, fn($v) => $v < 500000, ARRAY_FILTER_USE_BOTH);
        return serialLookup($n, $late);
    }

    // Jakarta, Indonesia (J prefix) - J + 2-digit year code + 5-6 digits
    if (preg_match('/^J(\d{2})\d{5,6}$/', $serial, $m)) {
        $code = (int)$m[1];
        $indonesiaMap = [];
        for ($y = 1998, $c = 15; $y <= 2022; $y++, $c++) {
            $indonesiaMap[$c] = $y;
        }
        return isset($indonesiaMap[$code]) ? (string)$indonesiaMap[$code] : null;
    }

    // South Haven, Michigan (U prefix)
    if (preg_match('/^U(\d{6})$/', $serial, $m)) {
        $n = (int)$m[1];
        $michigan = [
            1974=>101000, 1975=>102000, 1976=>107000, 1977=>110000,
            1978=>117000, 1979=>124000, 1980=>132000, 1981=>141000,
            1982=>150000, 1983=>160000, 1984=>167000, 1985=>174000,
            1986=>186000,
        ];
        return serialLookup($n, $michigan);
    }

    // Taoyuan, Taiwan (YT prefix)
    if (preg_match('/^YT(\d{6})$/', $serial, $m)) {
        $n = (int)$m[1];
        $taiwan = [2004=>277800, 2005=>281000, 2006=>285000];
        return serialLookup($n, $taiwan);
    }

    // Hamamatsu, Japan (pure numeric)
    if (preg_match('/^\d{4,7}$/', $serial)) {
        $n = (int)$serial;
        $isGrand = (bool)preg_match('/\b[CGS]\d/i', $title);
        // Before 1972: single series
        $early = [
            1917=>1700,1918=>1800,1919=>1900,1920=>2100,1921=>2650,1922=>3150,
            1923=>3650,1924=>4250,1925=>4950,1926=>5700,1927=>6500,1928=>7751,
            1929=>8928,1930=>10163,1931=>11719,1932=>13368,1933=>15182,1934=>17939,
            1935=>19895,1936=>22397,1937=>25158,1938=>28000,1939=>30000,1940=>31900,
            1941=>33800,1942=>35600,1943=>37000,1944=>38000,1945=>38550,1947=>40000,
            1948=>40075,1949=>40675,1950=>42073,1951=>44262,1952=>47675,1953=>51266,
            1954=>57057,1955=>63400,1956=>69300,1957=>77000,1958=>89000,1959=>102000,
            1960=>124000,1961=>149000,1962=>188000,1963=>237000,1964=>298000,
            1965=>368000,1966=>489000,1967=>570000,1968=>685000,1969=>805000,
            1970=>960000,1971=>1130000,
        ];
        if ($n < 1317500) return serialLookup($n, $early);
        // 1972+ split upright/grand
        $upright = [
            1972=>1317500,1973=>1510500,1974=>1745000,1975=>1945000,1976=>2154000,
            1977=>2384000,1978=>2585000,1979=>2810500,1980=>3001000,1981=>3261000,
            1982=>3465000,1983=>3646200,1984=>3832200,1985=>3987600,1986=>4156500,
            1987=>4334800,1988=>4491300,1989=>4672700,1990=>4837200,1991=>4967900,
            1992=>5086800,1993=>5204100,1994=>5296400,1995=>5375000,1996=>5446000,
            1997=>5530000,1998=>5579000,1999=>5792000,
        ];
        $grand = [
            1972=>1358500,1973=>1538500,1974=>1753500,1975=>1935000,1976=>2153000,
            1977=>2362000,1978=>2580500,1979=>2848000,1980=>3040000,1981=>3270000,
            1982=>3490000,1983=>3710500,1984=>3891600,1985=>4040700,1986=>4214600,
            1987=>4351100,1988=>4561000,1989=>4671400,1990=>4810900,1991=>4951200,
            1992=>5071800,1993=>5181400,1994=>5291500,1995=>5368000,1996=>5448000,
            1997=>5502000,1998=>5588000,1999=>5810000,
        ];
        $unified = [
            2000=>5860000,2001=>5920000,2002=>5970000,2003=>6020000,2004=>6060000,
            2005=>6100000,2006=>6145000,2007=>6191000,2008=>6220000,2009=>6250000,
            2010=>6280000,2011=>6310000,2012=>6340000,2013=>6360000,2014=>6380000,
            2015=>6400000,2016=>6420000,2017=>6440000,2018=>6460000,2019=>6480000,
            2020=>6500000,2021=>6520000,
        ];
        if ($n >= 5860000) return serialLookup($n, $unified);
        $table = $isGrand ? $grand : $upright;
        return serialLookup($n, $table);
    }

    return null;
}

function serialLookup(int $n, array $table): ?string {
    ksort($table);
    $result = null;
    foreach ($table as $year => $start) {
        if ($n >= $start) $result = (string)$year;
        else break;
    }
    return $result;
}

function classifyCondition(string $title, string $link, string $store, string $desc = ''): string {
    $text = mb_strtolower($title . ' ' . $link . ' ' . $desc);
    $newKw = ['nuevo','nou a estrenar','brand new','nieuw','fabrica'];
    foreach ($newKw as $kw) {
        if (str_contains($text, $kw)) return 'nou';
    }
    $usedKw = ['segunda mano','ocasion','ocasió','occasion','gebraucht','used','renovad',
               'restaur','recondicion','reacondicion','seminuev','tweedehands','2nd hand',
               'pre-owned','preloved'];
    foreach ($usedKw as $kw) {
        if (str_contains($text, $kw)) return '2a_ma';
    }
    // Marketplaces are second-hand by default
    $mpStores = ['Wallapop','Kleinanzeigen','Marktplaats','eBay','Leboncoin','PianoMart','2dehands.be','2ememain.be','Yahoo Auctions JP','OLX.pl'];
    if (in_array($store, $mpStores)) return '2a_ma';
    // Specialist second-hand stores
    $usedStores = ['Art Guinardo','Pianos Low Cost','La Casa dels Pianos','Pianos Can Puig','Sinergia Music','Jorquera Pianos','Japan Used Piano'];
    if (in_array($store, $usedStores)) return '2a_ma';
    return 'desconegut';
}

function extractPrice(string $text): string {
    $text = str_replace("\xc2\xa0", ' ', $text); // non-breaking space
    if (preg_match('/([\d]{1,3}(?:[.,]\d{3})*(?:[.,]\d{1,2})?)\s*(?:€|EUR)/i', $text, $m)) {
        return $m[1] . ' EUR';
    }
    if (preg_match('/(?:€|EUR)\s*([\d]{1,3}(?:[.,]\d{3})*(?:[.,]\d{1,2})?)/', $text, $m)) {
        return $m[1] . ' EUR';
    }
    return '';
}

function extractPrestashopPrice(string $itemHtml): string {
    // Best: structured data content="8500"
    if (preg_match('/itemprop="price"\s+content="([\d.]+)"/i', $itemHtml, $m)) {
        $val = (float)$m[1];
        return $val > 0 ? number_format($val, 0, ',', '.') . ' EUR' : '';
    }
    if (preg_match('/content="([\d.]+)"\s+class="price"/i', $itemHtml, $m)) {
        $val = (float)$m[1];
        return $val > 0 ? number_format($val, 0, ',', '.') . ' EUR' : '';
    }
    // Fallback: visible price text
    if (preg_match('/class="price"[^>]*>(.*?)<\/span>/si', $itemHtml, $m)) {
        $p = extractPrice($m[1]);
        if ($p) return $p;
    }
    return '';
}

// Helper: extract products from a PrestaShop page (category or search results)
function scrapePrestashopProducts(string $body): array {
    $found = [];
    // Strategy 1: standard PrestaShop product-miniature articles
    if (preg_match_all('/<article[^>]*class="[^"]*product-miniature[^"]*"[^>]*>(.*?)<\/article>/si', $body, $items)) {
        foreach ($items[1] as $item) {
            $title = ''; $price = ''; $link = ''; $img = '';
            if (preg_match('/<h[234][^>]*>\s*<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/si', $item, $m)) {
                $link = $m[1];
                $title = clean($m[2]);
            }
            $price = extractPrestashopPrice($item);
            if (preg_match('/<img[^>]+(?:data-full-size-image-url|data-src|src)="([^"]+)"/i', $item, $m)) {
                $img = $m[1];
            }
            if ($title && $link) {
                $found[] = ['title' => $title, 'price' => $price, 'link' => $link, 'img' => $img];
            }
        }
    }
    // Strategy 2: fallback for newer themes with product-card or product_card divs
    if (empty($found) && preg_match_all('/<div[^>]*class="[^"]*product[-_]card[^"]*"[^>]*>(.*?)<\/div>\s*<\/div>/si', $body, $items)) {
        foreach ($items[1] as $item) {
            $title = ''; $price = ''; $link = ''; $img = '';
            if (preg_match('/<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/si', $item, $m)) {
                $link = $m[1];
                $title = clean($m[2]);
            }
            if (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $item, $m)) {
                $price = trim($m[1]) . ' EUR';
            }
            if (preg_match('/<img[^>]+(?:data-src|src)="([^"]+)"/i', $item, $m)) {
                $img = $m[1];
            }
            if ($title && $link && strlen($title) > 3) {
                $found[] = ['title' => $title, 'price' => $price, 'link' => $link, 'img' => $img];
            }
        }
    }
    return $found;
}

// Helper: crawl PrestaShop pages (category + search) with pagination
function crawlPrestashop(array $categoryUrls, ?string $searchUrl, string $storeName, string $location, string $searchModel, string $descDefault = ''): array {
    $found = [];
    $seenLinks = [];

    // Extract model code for quick relevance check
    $modelClean = mb_strtolower(preg_replace('/\byamaha\b/i', '', $searchModel));
    $modelQuick = trim(preg_replace('/[\s\-]+/', '', $modelClean));

    // Search first (most relevant), then categories
    $allUrls = [];
    if ($searchUrl) $allUrls[] = $searchUrl;
    foreach ($categoryUrls as $cu) $allUrls[] = $cu;

    foreach ($allUrls as $baseUrl) {
        for ($page = 1; $page <= 2; $page++) {
            $url = $baseUrl;
            if ($page > 1) {
                $url .= (strpos($baseUrl, '?') !== false ? '&' : '?') . 'page=' . $page;
            }
            $body = fetch($url, 12);
            if (!$body) break;

            $products = scrapePrestashopProducts($body);
            if (empty($products)) break;

            foreach ($products as $p) {
                if (isset($seenLinks[$p['link']])) continue;
                $seenLinks[$p['link']] = true;

                $yearInfo = extractYearEx($p['title'], $p['title']);
                $desc = $descDefault ?: 'segunda mano';

                // If product likely matches our model, fetch its page for year/details
                $titleLower = mb_strtolower($p['title'] . ' ' . $p['link']);
                $looksRelevant = str_contains(str_replace(['-',' ','.'], '', $titleLower), $modelQuick);
                if ($looksRelevant && !$yearInfo['year']) {
                    $pBody = fetch($p['link'], 8);
                    if ($pBody) {
                        $yearInfo = extractYearFromPage($pBody, $p['title']);
                        $pText = strip_tags($pBody);
                        $pText = html_entity_decode($pText, ENT_QUOTES, 'UTF-8');
                        $extraDesc = '';
                        if (preg_match('/(?:descripci[oó]n|detall|caracter[ií]stic)[^:]*[:]\s*(.{20,200})/si', $pText, $dm)) {
                            $extraDesc = trim(preg_replace('/\s+/', ' ', $dm[1]));
                        }
                        if ($extraDesc) $desc = mb_substr($extraDesc, 0, 150);
                    }
                }

                $found[] = [
                    'store'    => $storeName,
                    'location' => $location,
                    'title'    => $p['title'],
                    'year'     => $yearInfo['year'],
                    'year_confidence' => $yearInfo['confidence'],
                    'price'    => $p['price'] ?: '-',
                    'link'     => $p['link'],
                    'image'    => $p['img'],
                    'desc'     => $desc,
                ];
            }
        }
    }
    return $found;
}

$results = [];

// ══════════════════════════════════════════════════════════════
// 1) LA CASA DELS PIANOS (Barcelona) - WordPress search
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['catalunya', 'espanya', 'europa'])) {
    try {
        scraper('La Casa dels Pianos');
        $q = urlencode($searchModel);
        $body = fetch("https://lacasadelspianos.com/es/?s={$q}", 15);

        if ($body) {
            // Find product links in search results
            if (preg_match_all('/href="(https?:\/\/lacasadelspianos\.com\/es\/pianos-item\/[^"]+)"/i', $body, $links)) {
                $productLinks = array_unique($links[1]);
                foreach (array_slice($productLinks, 0, 6) as $pLink) {
                    $pBody = fetch($pLink, 10);
                    if (!$pBody) continue;

                    $title = ''; $price = ''; $img = '';
                    // Title from <h1>
                    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/si', $pBody, $m)) {
                        $raw = clean($m[1]);
                        // Title often contains price: "Yamaha C2 – 11.000€" or "Yamaha U3 – 4.900 €"
                        if (preg_match('/^(.*?)\s*[-–—]+\s*([\d.,]+)\s*(?:€|EUR)/i', $raw, $tp)) {
                            $title = trim($tp[1]);
                            $price = trim($tp[2]) . ' EUR';
                        } else {
                            $title = $raw;
                        }
                    }
                    // Fallback price from body
                    if (!$price && preg_match('/[Pp]recio[:\s]*([\d.,]+)\s*(?:€|EUR)/i', $pBody, $m)) {
                        $price = trim($m[1]) . ' EUR';
                    }
                    if (!$price && preg_match('/([\d]{1,3}(?:[.,]\d{3})*)\s*(?:€|EUR)/i', $pBody, $m)) {
                        $price = trim($m[1]) . ' EUR';
                    }
                    // Image - prefer product images, not logo
                    if (preg_match_all('/<img[^>]+src="(https?:\/\/lacasadelspianos\.com\/wp-content\/uploads\/[^"]+)"/i', $pBody, $imgs)) {
                        foreach ($imgs[1] as $imgCandidate) {
                            if (!str_contains(strtolower($imgCandidate), 'logo')) {
                                $img = $imgCandidate;
                                break;
                            }
                        }
                        if (!$img) $img = $imgs[1][0];
                    }
                    // Description + condition from product details
                    $desc = '';
                    if (preg_match('/<div[^>]*class="[^"]*entry-content[^"]*"[^>]*>(.*?)<\/div>/si', $pBody, $m)) {
                        $desc = mb_substr(clean($m[1]), 0, 150);
                    }
                    if (preg_match('/Tipo.*?<\/strong>\s*(.*?)(?:<br|<\/)/si', $pBody, $m)) {
                        $desc .= ' Tipo: ' . clean($m[1]);
                    }

                    if ($title) {
                        $yearInfo = extractYearEx($title . ' ' . $desc, $title);
                        if (!$yearInfo['year']) $yearInfo = extractYearFromPage($pBody, $title);
                        $results[] = [
                            'store'    => 'La Casa dels Pianos',
                            'location' => 'Barcelona, Catalunya',
                            'title'    => $title,
                            'year'     => $yearInfo['year'],
                            'year_confidence' => $yearInfo['confidence'],
                            'price'    => $price ?: '-',
                            'link'     => $pLink,
                            'image'    => $img,
                            'desc'     => $desc,
                        ];
                    }
                }
            }
        }
    
        scraperDone('La Casa dels Pianos');
    } catch (\Throwable $e) { scraperFail('La Casa dels Pianos');}
}

// ══════════════════════════════════════════════════════════════
// 2) ART GUINARDO (Barcelona) - Search + category pages
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['catalunya', 'espanya', 'europa'])) {
    try {
        scraper('Art Guinardo');
        $q = urlencode($searchModel);
        $searchUrl = "https://www.artguinardo.com/busqueda?s={$q}";
        $categories = [
            'https://www.artguinardo.com/112-pianos-yamaha-verticales-segunda-mano',
            'https://www.artguinardo.com/115-pianos-yamaha-de-cola-de-segunda-mano',
        ];
        $found = crawlPrestashop($categories, $searchUrl, 'Art Guinardo', 'Barcelona, Catalunya', $searchModel, 'segunda mano');
        array_push($results, ...$found);
        scraperDone('Art Guinardo');
    } catch (\Throwable $e) { scraperFail('Art Guinardo');}
}

// ══════════════════════════════════════════════════════════════
// 3) AUDENIS (Barcelona) - Search + category pages
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['catalunya', 'espanya', 'europa'])) {
    try {
        scraper('Audenis');
        $q = urlencode($searchModel);
        $searchUrl = "https://audenisbcn.com/es/busqueda?s={$q}";
        $categories = [
            'https://audenisbcn.com/es/317-piano-ocasion',
        ];
        $found = crawlPrestashop($categories, $searchUrl, 'Audenis', 'Barcelona, Catalunya', $searchModel, 'ocasion');
        array_push($results, ...$found);
        scraperDone('Audenis');
    } catch (\Throwable $e) { scraperFail('Audenis');}
}

// ══════════════════════════════════════════════════════════════
// 4) PIANOS LOW COST (Madrid) - Search + category pages
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['espanya', 'europa'])) {
    try {
        scraper('Pianos Low Cost');
        $q = urlencode($searchModel);
        $searchUrl = "https://www.pianoslowcost.es/busqueda?s={$q}";
        $categories = [
            'https://www.pianoslowcost.es/7-pianos-verticales-renovados',
            'https://www.pianoslowcost.es/11-pianos-cola-renovados',
            'https://www.pianoslowcost.es/8-pianos-de-ocasion-revisados',
            'https://www.pianoslowcost.es/10-pianos-de-ocasion-revisados',
        ];
        $found = crawlPrestashop($categories, $searchUrl, 'Pianos Low Cost', 'Madrid, Espanya', $searchModel, 'renovado ocasion');
        array_push($results, ...$found);
        scraperDone('Pianos Low Cost');
    } catch (\Throwable $e) { scraperFail('Pianos Low Cost');}
}

// ══════════════════════════════════════════════════════════════
// 5) CORRALES PIANOS (Barcelona) - WordPress search + category
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['catalunya', 'espanya', 'europa'])) {
    try {
        scraper('Corrales Pianos');
        $cpSeen = [];

        // WordPress search
        $q = urlencode($searchModel);
        $searchBody = fetch("https://www.corralespianos.com/?s={$q}", 15);
        if ($searchBody) {
            if (preg_match_all('/href="(https?:\/\/www\.corralespianos\.com\/[^"]*pianos?[^"]*)"/i', $searchBody, $links)) {
                foreach (array_slice(array_unique($links[1]), 0, 6) as $pLink) {
                    if (isset($cpSeen[$pLink])) continue;
                    $cpSeen[$pLink] = true;
                    $pBody = fetch($pLink, 10);
                    if (!$pBody) continue;

                    $title = ''; $price = ''; $img = '';
                    if (preg_match('/<h[12][^>]*class="[^"]*entry-title[^"]*"[^>]*>(.*?)<\/h[12]>/si', $pBody, $m)) {
                        $title = clean($m[1]);
                    }
                    if (!$title && preg_match('/<h[12][^>]*>(.*?)<\/h[12]>/si', $pBody, $m)) {
                        $title = clean($m[1]);
                    }
                    if (preg_match('/(?:A partir de|Precio|PVP|Preu)[:\s]*([\d.,]+)\s*(?:€|EUR)/i', $pBody, $m)) {
                        $price = trim($m[1]) . ' EUR';
                    }
                    if (preg_match_all('/<img[^>]+src="(https?:\/\/www\.corralespianos\.com\/wp-content\/uploads\/[^"]+)"/i', $pBody, $imgs)) {
                        foreach ($imgs[1] as $imgCandidate) {
                            if (!str_contains(strtolower($imgCandidate), 'logo')) {
                                $img = $imgCandidate;
                                break;
                            }
                        }
                        if (!$img) $img = $imgs[1][0];
                    }

                    if ($title) {
                        $yearInfo = extractYearEx($title, $title);
                        $results[] = [
                            'store'    => 'Corrales Pianos',
                            'location' => 'Barcelona, Catalunya',
                            'title'    => $title,
                            'year'     => $yearInfo['year'],
                            'year_confidence' => $yearInfo['confidence'],
                            'price'    => $price ?: 'Consultar',
                            'link'     => $pLink,
                            'image'    => $img,
                            'desc'     => 'ocasion',
                        ];
                    }
                }
            }
        }

        // Also crawl category page
        $catBody = fetch("https://www.corralespianos.com/pianos-de-ocasion/", 15);
        if ($catBody) {
            if (preg_match_all('/href="(https?:\/\/www\.corralespianos\.com\/[^"]+)"/i', $catBody, $links)) {
                $catLinks = 0;
                foreach (array_unique($links[1]) as $pLink) {
                    if ($catLinks >= 6) break;
                    if (isset($cpSeen[$pLink]) || str_contains($pLink, '#') || str_contains($pLink, 'wp-content')) continue;
                    if (!preg_match('/piano/i', $pLink)) continue;
                    $cpSeen[$pLink] = true;
                    $catLinks++;
                    $pBody = fetch($pLink, 8);
                    if (!$pBody) continue;

                    $title = ''; $price = ''; $img = '';
                    if (preg_match('/<h[12][^>]*>(.*?)<\/h[12]>/si', $pBody, $m)) {
                        $title = clean($m[1]);
                    }
                    if (preg_match('/(?:A partir de|Precio|PVP|Preu)[:\s]*([\d.,]+)\s*(?:€|EUR)/i', $pBody, $m)) {
                        $price = trim($m[1]) . ' EUR';
                    }
                    if (preg_match_all('/<img[^>]+src="(https?:\/\/www\.corralespianos\.com\/wp-content\/uploads\/[^"]+)"/i', $pBody, $imgs)) {
                        foreach ($imgs[1] as $imgCandidate) {
                            if (!str_contains(strtolower($imgCandidate), 'logo')) { $img = $imgCandidate; break; }
                        }
                        if (!$img) $img = $imgs[1][0];
                    }

                    if ($title) {
                        $yearInfo = extractYearEx($title, $title);
                        $results[] = [
                            'store'    => 'Corrales Pianos',
                            'location' => 'Barcelona, Catalunya',
                            'title'    => $title,
                            'year'     => $yearInfo['year'],
                            'year_confidence' => $yearInfo['confidence'],
                            'price'    => $price ?: 'Consultar',
                            'link'     => $pLink,
                            'image'    => $img,
                            'desc'     => 'ocasion',
                        ];
                    }
                }
            }
        }

        scraperDone('Corrales Pianos');
    } catch (\Throwable $e) { scraperFail('Corrales Pianos');}
}

// ══════════════════════════════════════════════════════════════
// 6) PIANOS CAN PUIG (Mataró) - Shopify JSON API
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['catalunya', 'espanya', 'europa'])) {
    try {
        scraper('Pianos Can Puig');
        $cpCollections = [
            'https://pianoscanpuig.com/search/suggest.json?q=' . urlencode($searchModel) . '&resources[type]=product',
            'https://pianoscanpuig.com/collections/pianos-de-ocasion/products.json',
            'https://pianoscanpuig.com/collections/pianos-de-re-estreno/products.json',
        ];
        $cpSeenHandles = [];
        foreach ($cpCollections as $cpUrl) {
            $body = fetch($cpUrl);
            if (!$body) continue;
            $json = json_decode($body, true);
            $products = $json['products'] ?? $json['resources']['results']['products'] ?? [];
            foreach ($products as $p) {
                $title = $p['title'] ?? '';
                $price = '';
                if (!empty($p['variants'][0]['price'])) {
                    $val = (float)$p['variants'][0]['price'];
                    $price = $val > 0 ? number_format($val, 0, ',', '.') . ' EUR' : '-';
                }
                $img = $p['images'][0]['src'] ?? '';
                $handle = $p['handle'] ?? '';
                $link = $handle ? "https://pianoscanpuig.com/products/{$handle}" : '';
                $desc = mb_substr(strip_tags($p['body_html'] ?? ''), 0, 150);

                if ($title && $link) {
                    $yearInfo = extractYearEx($title . ' ' . $desc, $title);
                    $results[] = [
                        'store'    => 'Pianos Can Puig',
                        'location' => 'Mataro, Catalunya',
                        'title'    => clean($title),
                        'year'     => $yearInfo['year'],
                        'year_confidence' => $yearInfo['confidence'],
                        'price'    => $price ?: '-',
                        'link'     => $link,
                        'image'    => $img,
                        'desc'     => 'ocasion ' . clean($desc),
                    ];
                }
            }
        }
    
        scraperDone('Pianos Can Puig');
    } catch (\Throwable $e) { scraperFail('Pianos Can Puig');}
}

// ══════════════════════════════════════════════════════════════
// 7) SINERGIA MUSIC (Mataró) - Search + category pages
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['catalunya', 'espanya', 'europa'])) {
    try {
        scraper('Sinergia Music');
        $q = urlencode($searchModel);
        $searchUrl = "https://sinergiamusic.es/busqueda?s={$q}";
        $categories = [
            'https://sinergiamusic.es/392-piano-segunda-mano',
        ];
        $found = crawlPrestashop($categories, $searchUrl, 'Sinergia Music', 'Mataro, Catalunya', $searchModel, 'segunda mano ocasion');

        // Fallback: try legacy title-based extraction if Prestashop helper found nothing
        if (empty($found)) {
            $smSeen = [];
            foreach ([$categories[0]] as $smUrl) {
                $body = fetch($smUrl, 12);
                if (!$body) continue;
                if (preg_match_all('/<a[^>]+href="(https:\/\/sinergiamusic\.es\/[^"]*\.html)"[^>]+title="([^"]+)"/si', $body, $pms, PREG_SET_ORDER)) {
                    foreach ($pms as $pm) {
                        $pLink = $pm[1];
                        $title = clean($pm[2]);
                        if (isset($smSeen[$pLink]) || !$title) continue;
                        $smSeen[$pLink] = true;

                        $price = '';
                        $pos = strpos($body, $pLink);
                        if ($pos !== false) {
                            $chunk = substr($body, $pos, 2000);
                            if (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $chunk, $pm2)) {
                                $price = trim($pm2[1]) . ' EUR';
                            }
                        }
                        $img = '';
                        if (preg_match('/href="' . preg_quote($pLink, '/') . '"[^>]*>\s*<img[^>]+(?:data-src|src)="([^"]+)"/si', $body, $im)) {
                            $img = $im[1];
                        }

                        if ($title) {
                            $yearInfo = extractYearEx($title, $title);
                            $found[] = [
                                'store'    => 'Sinergia Music',
                                'location' => 'Mataro, Catalunya',
                                'title'    => $title,
                                'year'     => $yearInfo['year'],
                                'year_confidence' => $yearInfo['confidence'],
                                'price'    => $price ?: '-',
                                'link'     => $pLink,
                                'image'    => $img,
                                'desc'     => 'segunda mano ocasion',
                            ];
                        }
                    }
                }
            }
        }
        array_push($results, ...$found);
        scraperDone('Sinergia Music');
    } catch (\Throwable $e) { scraperFail('Sinergia Music');}
}

// ══════════════════════════════════════════════════════════════
// 8) JORQUERA PIANOS (Barcelona) - WordPress text parsing
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['catalunya', 'espanya', 'europa'])) {
    try {
        scraper('Jorquera Pianos');
        $jqPages = [
            'https://jorquerapianos.com/comprar-piano-de-reestreno/pianos-verticales-de-segunda-mano/',
            'https://jorquerapianos.com/comprar-piano-de-reestreno/pianos-de-cola-de-segunda-mano/',
        ];
        foreach ($jqPages as $jqUrl) {
            $body = fetch($jqUrl, 20);
            if (!$body) continue;

            $text = strip_tags($body);
            $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
            $text = str_replace("\xc2\xa0", ' ', $text);
            $text = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', fn($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $text);
            if (preg_match_all('/(?:Yamaha|YAMAHA)\s+([A-Z0-9][A-Z0-9\-]{0,10})/i', $text, $jqMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                $jqSeen = [];
                foreach ($jqMatches as $jqm) {
                    $jqModel = trim($jqm[1][0]);
                    if (strlen($jqModel) < 2 || isset($jqSeen[$jqModel])) continue;
                    $jqSeen[$jqModel] = true;
                    $jqOffset = $jqm[0][1];
                    $chunk = substr($text, $jqOffset, 300);
                    $sold = preg_match('/(?:Reservado|Vendido)/i', $chunk);
                    if ($sold) continue;
                    $price = '';
                    if (preg_match('/Precio[:\s]*([\d.,]+)\s*€/i', $chunk, $pm)) {
                        $price = trim($pm[1]) . ' EUR';
                    }
                    $yearInfo = extractYearEx($chunk, 'Yamaha ' . $jqModel);
                    $results[] = [
                        'store'    => 'Jorquera Pianos',
                        'location' => 'Barcelona, Catalunya',
                        'title'    => 'Yamaha ' . $jqModel,
                        'year'     => $yearInfo['year'],
                        'year_confidence' => $yearInfo['confidence'],
                        'price'    => $price ?: 'Consultar',
                        'link'     => $jqUrl,
                        'image'    => '',
                        'desc'     => 'reestreno segunda mano',
                    ];
                }
            }
        }
    
        scraperDone('Jorquera Pianos');
    } catch (\Throwable $e) { scraperFail('Jorquera Pianos');}
}

// ══════════════════════════════════════════════════════════════
// 9) KLEINANZEIGEN.DE (Alemanya) - JSON-LD
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        scraper('Kleinanzeigen');
        $q = urlencode($searchModel);
        $body = fetch("https://www.kleinanzeigen.de/s-musikinstrumente/{$q}/k0c74");

        if ($body && preg_match_all('/class="aditem\b[^"]*"[^>]*>(.*?)<\/article>/si', $body, $items)) {
            foreach (array_slice($items[1], 0, 12) as $item) {
                $title = ''; $img = ''; $desc = ''; $price = ''; $link = ''; $loc = '';

                if (preg_match('/application\/ld\+json">(.*?)<\/script>/si', $item, $m)) {
                    $ld = json_decode($m[1], true);
                    $title = $ld['title'] ?? '';
                    $img   = $ld['contentUrl'] ?? '';
                    $desc  = mb_substr($ld['description'] ?? '', 0, 150);
                }
                if (preg_match('/href="(\/s-anzeige\/[^"]*)"/', $item, $m)) {
                    $link = 'https://www.kleinanzeigen.de' . $m[1];
                }
                if (preg_match('/aditem-main--middle--price-shipping--price[^>]*>\s*(.*?)\s*<\/p>/si', $item, $m)) {
                    $price = clean($m[1]);
                } elseif (preg_match('/(\d[\d\.]+)\s*€/', $item, $m)) {
                    $price = $m[0];
                }
                if (preg_match('/aditem-main--top--left[^>]*>.*?<\/i>\s*(.*?)\s*<\/div>/si', $item, $m)) {
                    $loc = clean($m[1]);
                }

                if ($title && $link) {
                    $yearInfo = extractYearEx($title . ' ' . $desc, $title);
                    $results[] = [
                        'store'    => 'Kleinanzeigen',
                        'location' => ($loc ?: 'Alemanya') . ', Alemanya',
                        'title'    => clean($title),
                        'year'     => $yearInfo['year'],
                        'year_confidence' => $yearInfo['confidence'],
                        'price'    => $price ?: '-',
                        'link'     => $link,
                        'image'    => $img,
                        'desc'     => clean($desc),
                    ];
                }
            }
        }
    
        scraperDone('Kleinanzeigen');
    } catch (\Throwable $e) { scraperFail('Kleinanzeigen');}
}

// ══════════════════════════════════════════════════════════════
// 10) MARKTPLAATS.NL (Holanda) - __NEXT_DATA__
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        scraper('Marktplaats');
        $q = urlencode($searchModel . ' piano');
        $body = fetch("https://www.marktplaats.nl/q/{$q}/");

        if ($body && preg_match('/__NEXT_DATA__[^>]*>(.*?)<\/script>/si', $body, $m)) {
            $json = json_decode($m[1], true);
            $listings = $json['props']['pageProps']['searchRequestAndResponse']['listings'] ?? [];

            foreach (array_slice($listings, 0, 12) as $l) {
                $title = $l['title'] ?? '';
                $priceCents = $l['priceInfo']['priceCents'] ?? 0;
                $priceType  = $l['priceInfo']['priceType'] ?? '';
                $city       = $l['location']['cityName'] ?? '';
                $vipUrl     = $l['vipUrl'] ?? '';
                $img        = $l['imageUrls'][0] ?? '';
                $desc       = $l['description'] ?? '';

                $priceStr = $priceCents > 0 ? number_format($priceCents / 100, 0, ',', '.') . ' EUR' : ($priceType ?: '-');
                $linkUrl  = $vipUrl ? "https://www.marktplaats.nl{$vipUrl}" : '';

                if ($title && $linkUrl) {
                    $yearInfo = extractYearEx($title . ' ' . $desc, $title);
                    $results[] = [
                        'store'    => 'Marktplaats',
                        'location' => ($city ?: 'Holanda') . ', Holanda',
                        'title'    => clean($title),
                        'year'     => $yearInfo['year'],
                        'year_confidence' => $yearInfo['confidence'],
                        'price'    => $priceStr,
                        'link'     => $linkUrl,
                        'image'    => $img,
                        'desc'     => clean(mb_substr($desc, 0, 150)),
                    ];
                }
            }
        }
    
        scraperDone('Marktplaats');
    } catch (\Throwable $e) { scraperFail('Marktplaats');}
}

// ══════════════════════════════════════════════════════════════
// 10b) 2DEHANDS.BE (Bèlgica) - __NEXT_DATA__ (same platform as Marktplaats)
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        scraper('2dehands.be');
        $q = urlencode($searchModel . ' piano');
        $body = fetch("https://www.2dehands.be/q/{$q}/");

        if ($body && preg_match('/__NEXT_DATA__[^>]*>(.*?)<\/script>/si', $body, $m)) {
            $json = json_decode($m[1], true);
            $listings = $json['props']['pageProps']['searchRequestAndResponse']['listings'] ?? [];

            foreach (array_slice($listings, 0, 12) as $l) {
                $title = $l['title'] ?? '';
                $priceCents = $l['priceInfo']['priceCents'] ?? 0;
                $priceType  = $l['priceInfo']['priceType'] ?? '';
                $city       = $l['location']['cityName'] ?? '';
                $vipUrl     = $l['vipUrl'] ?? '';
                $img        = $l['imageUrls'][0] ?? '';
                $desc       = $l['description'] ?? '';

                $priceStr = $priceCents > 0 ? number_format($priceCents / 100, 0, ',', '.') . ' EUR' : ($priceType ?: '-');
                $linkUrl  = $vipUrl ? "https://www.2dehands.be{$vipUrl}" : '';

                if ($title && $linkUrl) {
                    $yearInfo = extractYearEx($title . ' ' . $desc, $title);
                    $results[] = [
                        'store'    => '2dehands.be',
                        'location' => ($city ?: 'Belgica') . ', Belgica',
                        'title'    => clean($title),
                        'year'     => $yearInfo['year'],
                        'year_confidence' => $yearInfo['confidence'],
                        'price'    => $priceStr,
                        'link'     => $linkUrl,
                        'image'    => $img,
                        'desc'     => clean(mb_substr($desc, 0, 150)),
                    ];
                }
            }
        }
    
        scraperDone('2dehands.be');
    } catch (\Throwable $e) { scraperFail('2dehands.be');}
}

// ══════════════════════════════════════════════════════════════
// 10b-bis) 2EMEMAIN.BE (Bèlgica francòfona) - __NEXT_DATA__
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        scraper('2ememain.be');
        $q = urlencode($searchModel . ' piano');
        $body = fetch("https://www.2ememain.be/q/{$q}/");

        if ($body && preg_match('/__NEXT_DATA__[^>]*>(.*?)<\/script>/si', $body, $m)) {
            $json = json_decode($m[1], true);
            $listings = $json['props']['pageProps']['searchRequestAndResponse']['listings'] ?? [];

            foreach (array_slice($listings, 0, 12) as $l) {
                $title = $l['title'] ?? '';
                $priceCents = $l['priceInfo']['priceCents'] ?? 0;
                $priceType  = $l['priceInfo']['priceType'] ?? '';
                $city       = $l['location']['cityName'] ?? '';
                $vipUrl     = $l['vipUrl'] ?? '';
                $img        = $l['imageUrls'][0] ?? '';
                $desc       = $l['description'] ?? '';

                $priceStr = $priceCents > 0 ? number_format($priceCents / 100, 0, ',', '.') . ' EUR' : ($priceType ?: '-');
                $linkUrl  = $vipUrl ? "https://www.2ememain.be{$vipUrl}" : '';

                if ($title && $linkUrl) {
                    $yearInfo = extractYearEx($title . ' ' . $desc, $title);
                    $results[] = [
                        'store'    => '2ememain.be',
                        'location' => ($city ?: 'Belgica') . ', Belgica',
                        'title'    => clean($title),
                        'year'     => $yearInfo['year'],
                        'year_confidence' => $yearInfo['confidence'],
                        'price'    => $priceStr,
                        'link'     => $linkUrl,
                        'image'    => $img,
                        'desc'     => clean(mb_substr($desc, 0, 150)),
                    ];
                }
            }
        }

        scraperDone('2ememain.be');
    } catch (\Throwable $e) { scraperFail('2ememain.be');}
}

// ══════════════════════════════════════════════════════════════
// 10c) PIANO IMPORTA (València) - WooCommerce search
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['espanya', 'europa'])) {
    try {
        scraper('Piano Importa');
        $q = urlencode($searchModel);
        $body = fetch("https://pianoimporta.com/?s={$q}&post_type=product");

        if ($body) {
            if (preg_match_all('/<li[^>]*class="[^"]*product[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
                foreach (array_slice($items[1], 0, 10) as $item) {
                    $title = ''; $price = ''; $link = ''; $img = '';

                    if (preg_match('/href="(https?:\/\/pianoimporta\.com\/producto\/[^"]+)"/i', $item, $m)) {
                        $link = $m[1];
                    }
                    if (preg_match('/class="woocommerce-loop-product__title"[^>]*>(.*?)<\//si', $item, $m)) {
                        $title = clean($m[1]);
                    }
                    if (!$title && preg_match('/<h[23][^>]*>(.*?)<\/h[23]>/si', $item, $m)) {
                        $title = clean($m[1]);
                    }
                    if (preg_match('/<img[^>]+src="([^"]+)"/i', $item, $m)) {
                        $img = $m[1];
                    }
                    if (preg_match('/<bdi>[^<]*&euro;[^<]*<\/span>([\d.,]+)<\/bdi>/si', $item, $m)) {
                        $val = (float) str_replace(',', '', trim($m[1]));
                        $price = number_format($val, 0, ',', '.') . ' EUR';
                    } elseif (preg_match('/&euro;\s*<\/span>\s*([\d.,]+)/si', $item, $m)) {
                        $val = (float) str_replace(',', '', trim($m[1]));
                        $price = number_format($val, 0, ',', '.') . ' EUR';
                    } elseif (preg_match('/amount"[^>]*>([\d.,]+)\s*(?:€|&euro;)/si', $item, $m)) {
                        $price = trim($m[1]) . ' EUR';
                    }

                    if ($title && $link) {
                        $isOcasion = (bool) preg_match('/ocasi[oó]n|segunda\s*mano|2[ªa]\s*m[aà]|used|gebraucht/i', $title . ' ' . $link);
                        $yearInfo = extractYearEx($title, $title);
                        if (!$yearInfo['year']) {
                            $pBody = fetch($link, 10);
                            if ($pBody) $yearInfo = extractYearFromPage($pBody, $title);
                        }
                        $results[] = [
                            'store'    => 'Piano Importa',
                            'location' => 'Valencia, Espanya',
                            'title'    => $title,
                            'year'     => $yearInfo['year'],
                            'year_confidence' => $yearInfo['confidence'],
                            'price'    => $price ?: '-',
                            'link'     => $link,
                            'image'    => $img,
                            'desc'     => $isOcasion ? 'ocasion segunda mano' : '',
                        ];
                    }
                }
            }
        }
    
        scraperDone('Piano Importa');
    } catch (\Throwable $e) { scraperFail('Piano Importa');}
}

// ══════════════════════════════════════════════════════════════
// 11a) PIANOMART (Global - marketplace de pianos)
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['japo', 'europa', 'espanya'])) {
    try {
        scraper('PianoMart');
        $q = urlencode($searchModel);
        $body = fetch("https://www.pianomart.com/buy-a-piano/piano-ads?AdSearchForm%5Bsearch%5D={$q}", 15);

        if ($body && preg_match_all('/<tr[^>]*data-key="(\d+)"[^>]*>(.*?)<\/tr>/si', $body, $rows, PREG_SET_ORDER)) {
            foreach (array_slice($rows, 0, 12) as $row) {
                $adId = $row[1];
                $html = $row[2];
                $cells = [];
                if (preg_match_all('/<td[^>]*>(.*?)<\/td>/si', $html, $cellMatches)) {
                    $cells = $cellMatches[1];
                }
                if (count($cells) < 8) continue;

                $year  = trim(strip_tags($cells[1]));
                $title = trim(strip_tags($cells[2]));
                $price = trim(strip_tags($cells[4]));
                $state = trim(strip_tags($cells[5]));
                $city  = trim(strip_tags($cells[6]));
                $link  = '';
                $img   = '';

                if (preg_match('/href="(\/buy-a-piano\/view\?id=\d+)"/', $cells[2], $m)) {
                    $link = 'https://www.pianomart.com' . $m[1];
                }
                if (preg_match('/src="([^"]+)"/', $cells[0], $m)) {
                    $img = $m[1];
                }

                $location = trim($city . ', ' . $state);
                if ($title && $link) {
                    $yearInfo = ['year' => '', 'confidence' => ''];
                    if ($year && preg_match('/^\d{4}$/', $year)) {
                        $yearInfo = ['year' => $year, 'confidence' => 'stated'];
                    }
                    if (!$yearInfo['year']) {
                        $yearInfo = extractYearEx($title, $title);
                    }
                    $results[] = [
                        'store'    => 'PianoMart',
                        'location' => $location ?: 'Global',
                        'title'    => clean($title),
                        'year'     => $yearInfo['year'],
                        'year_confidence' => $yearInfo['confidence'],
                        'price'    => $price ?: '-',
                        'link'     => $link,
                        'image'    => $img,
                        'desc'     => '',
                    ];
                }
            }
        }

        scraperDone('PianoMart');
    } catch (\Throwable $e) { scraperFail('PianoMart');}
}

// ══════════════════════════════════════════════════════════════
// 11b) JAPAN USED PIANO (japanusedpiano.com) - WooCommerce
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['japo'])) {
    try {
        scraper('Japan Used Piano');
        $q = urlencode($searchModel);
        $body = fetch("https://www.japanusedpiano.com/?s={$q}&post_type=product", 15);

        if ($body) {
            if (preg_match_all('/<li[^>]*class="[^"]*product[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
                foreach (array_slice($items[1], 0, 10) as $item) {
                    $title = ''; $price = ''; $link = ''; $img = '';

                    if (preg_match('/href="(https?:\/\/www\.japanusedpiano\.com\/product\/[^"]+)"/i', $item, $m)) {
                        $link = $m[1];
                    }
                    if (preg_match('/class="woocommerce-loop-product__title"[^>]*>(.*?)<\//si', $item, $m)) {
                        $title = clean($m[1]);
                    }
                    if (!$title && preg_match('/<h[23][^>]*>(.*?)<\/h[23]>/si', $item, $m)) {
                        $title = clean($m[1]);
                    }
                    if (preg_match('/<img[^>]+(?:src|data-src)="([^"]+)"/i', $item, $m)) {
                        $img = $m[1];
                    }
                    if (preg_match('/amount"[^>]*>([\d.,]+)/si', $item, $m)) {
                        $price = '$' . trim($m[1]);
                    } elseif (preg_match('/\$([\d,]+(?:\.\d{2})?)/', $item, $m)) {
                        $price = '$' . $m[1];
                    }

                    if ($title && $link) {
                        $yearInfo = extractYearEx($title, $title);
                        if (!$yearInfo['year']) {
                            $pBody = fetch($link, 10);
                            if ($pBody) $yearInfo = extractYearFromPage($pBody, $title);
                        }
                        $results[] = [
                            'store'    => 'Japan Used Piano',
                            'location' => 'Japó',
                            'title'    => $title,
                            'year'     => $yearInfo['year'],
                            'year_confidence' => $yearInfo['confidence'],
                            'price'    => $price ?: '-',
                            'link'     => $link,
                            'image'    => $img,
                            'desc'     => '',
                        ];
                    }
                }
            }
            if (!preg_match('/search-no-results/', $body)) {
                $categoryUrl = "https://www.japanusedpiano.com/product-category/yamaha/";
                $catBody = fetch($categoryUrl, 15);
                if ($catBody && preg_match_all('/href="(https?:\/\/www\.japanusedpiano\.com\/product\/[^"]+)"/i', $catBody, $catLinks)) {
                    $catProductLinks = array_unique($catLinks[1]);
                    foreach (array_slice($catProductLinks, 0, 8) as $pLink) {
                        $existsAlready = false;
                        foreach ($results as $r) { if ($r['link'] === $pLink) { $existsAlready = true; break; } }
                        if ($existsAlready) continue;

                        $pBody = fetch($pLink, 10);
                        if (!$pBody) continue;
                        $pTitle = '';
                        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/si', $pBody, $m)) $pTitle = clean($m[1]);
                        if (!$pTitle) continue;

                        $pImg = '';
                        if (preg_match('/<img[^>]+src="(https?:\/\/www\.japanusedpiano\.com\/wp-content\/uploads\/[^"]+)"/i', $pBody, $m)) {
                            $pImg = $m[1];
                        }
                        $pPrice = '';
                        if (preg_match('/\$([\d,]+(?:\.\d{2})?)/', $pBody, $m)) {
                            $pPrice = '$' . $m[1];
                        }

                        $yearInfo = extractYearEx($pTitle, $pTitle);
                        if (!$yearInfo['year']) $yearInfo = extractYearFromPage($pBody, $pTitle);

                        $results[] = [
                            'store'    => 'Japan Used Piano',
                            'location' => 'Japó',
                            'title'    => $pTitle,
                            'year'     => $yearInfo['year'],
                            'year_confidence' => $yearInfo['confidence'],
                            'price'    => $pPrice ?: '-',
                            'link'     => $pLink,
                            'image'    => $pImg,
                            'desc'     => '',
                        ];
                    }
                }
            }
        }

        scraperDone('Japan Used Piano');
    } catch (\Throwable $e) { scraperFail('Japan Used Piano');}
}

// ══════════════════════════════════════════════════════════════
// 11c) YAHOO AUCTIONS JAPAN (via web search fallback)
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['japo'])) {
    try {
        scraper('Yahoo Auctions JP');
        $q = urlencode($searchModel . ' ピアノ');
        $resp = fetchWithCode("https://auctions.yahoo.co.jp/search/search?p={$q}&auccat=22540&va={$q}&exflg=1&b=1&n=20", 15);

        if ($resp['code'] >= 400 || !$resp['body']) {
            $scrapersRun['Yahoo Auctions JP']['status'] = 'blocked';
            $scrapersRun['Yahoo Auctions JP']['note'] = 'Requereix IP japonesa';
        } else {
            $body = $resp['body'];
            if (preg_match_all('/<li[^>]*class="[^"]*Product[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
                foreach (array_slice($items[1], 0, 10) as $item) {
                    $title = ''; $price = ''; $link = ''; $img = '';

                    if (preg_match('/href="(https?:\/\/page\.auctions\.yahoo\.co\.jp\/[^"]+)"/i', $item, $m)) {
                        $link = $m[1];
                    }
                    if (preg_match('/Product__title[^>]*>(?:<a[^>]*>)?(.*?)(?:<\/a>)?<\//si', $item, $m)) {
                        $title = clean($m[1]);
                    }
                    if (preg_match('/Product__priceValue[^>]*>(.*?)<\//si', $item, $m)) {
                        $price = '¥' . clean($m[1]);
                    }
                    if (preg_match('/<img[^>]+src="([^"]+)"/i', $item, $m)) {
                        $img = $m[1];
                    }

                    if ($title && $link) {
                        $yearInfo = extractYearEx($title, $title);
                        $results[] = [
                            'store'    => 'Yahoo Auctions JP',
                            'location' => 'Japó',
                            'title'    => $title,
                            'year'     => $yearInfo['year'],
                            'year_confidence' => $yearInfo['confidence'],
                            'price'    => $price ?: '-',
                            'link'     => $link,
                            'image'    => $img,
                            'desc'     => '',
                        ];
                    }
                }
            }
            scraperDone('Yahoo Auctions JP');
        }
    } catch (\Throwable $e) { scraperFail('Yahoo Auctions JP');}
}

// ══════════════════════════════════════════════════════════════
// 12) EBAY (.es, .de, .com)
// ══════════════════════════════════════════════════════════════
$ebayDomains = [];
if (in_array($region, ['espanya', 'catalunya'])) $ebayDomains[] = 'www.ebay.es';
if ($region === 'europa') { $ebayDomains[] = 'www.ebay.es'; $ebayDomains[] = 'www.ebay.de'; }
if ($region === 'japo') { $ebayDomains[] = 'www.ebay.com'; }

foreach ($ebayDomains as $ebayDomain) {
    try {
        scraper('eBay');
        $q = urlencode($searchModel . ' piano');
        $body = fetch("https://{$ebayDomain}/sch/i.html?_nkw={$q}&_sacat=180015&LH_BIN=1&_sop=15", 12);

        if (!$body) { scraperFail('eBay'); continue; }
        if (preg_match_all('/<li[^>]*class="[^"]*s-item\s[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
            foreach (array_slice($items[1], 1, 10) as $item) {
                $title = ''; $price = ''; $link = ''; $img = '';
                if (preg_match('/class="s-item__title"[^>]*>(?:<span[^>]*>)?(.*?)(?:<\/span>)?<\//si', $item, $m)) $title = clean($m[1]);
                if (preg_match('/class="s-item__price"[^>]*>(.*?)<\/span>/si', $item, $m)) $price = clean($m[1]);
                if (preg_match('/href="(https?:\/\/www\.ebay\.[^"]*)"/', $item, $m)) $link = strtok($m[1], '?');
                if (preg_match('/<img[^>]*src="(https?:\/\/i\.ebayimg[^"]*)"/', $item, $m)) $img = $m[1];

                if ($title && mb_strlen($title) > 5 && !str_contains(strtolower($title), 'shop on ebay')) {
                    $country = str_contains($ebayDomain, '.de') ? 'Alemanya' : (str_contains($ebayDomain, '.com') ? 'Global' : 'Espanya');
                    $yearInfo = extractYearEx($title, $title);
                    $results[] = [
                        'store'    => 'eBay',
                        'location' => $country,
                        'title'    => $title,
                        'year'     => $yearInfo['year'],
                        'year_confidence' => $yearInfo['confidence'],
                        'price'    => $price ?: '-',
                        'link'     => $link,
                        'image'    => $img,
                        'desc'     => '',
                    ];
                }
            }
        }
    
        scraperDone('eBay');
    } catch (\Throwable $e) { scraperFail('eBay');}
}

// ══════════════════════════════════════════════════════════════
// 12) WALLAPOP (Espanya) - API JSON
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['espanya', 'catalunya', 'europa'])) {
    $wpLocations = [];
    if ($region === 'catalunya') {
        $wpLocations[] = ['41.3851', '2.1734', '200000'];   // Barcelona, 200km radius
    } elseif ($region === 'espanya' || $region === 'europa') {
        $wpLocations[] = ['40.0000', '-3.0000', '600000'];  // Centre d'Espanya, 600km radius (cobreix tot)
        $wpLocations[] = ['41.3851', '2.1734', '300000'];   // Barcelona, 300km
    }

    scraper('Wallapop');
    $wpSeenIds = [];
    foreach ($wpLocations as [$lat, $lon, $dist]) {
        try {
            $q = urlencode($searchModel . ' piano');
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => "https://api.wallapop.com/api/v3/general/search?keywords={$q}&latitude={$lat}&longitude={$lon}&distance={$dist}&filters_source=default_filters&order_by=newest",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_ENCODING       => '',
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json, text/plain, */*',
                    'X-DeviceOS: 0',
                ],
            ]);
            $wBody = curl_exec($ch);
            $wCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($wCode >= 400 || !$wBody) {
                $scrapersRun['Wallapop']['status'] = 'blocked';
            }
            if ($wCode >= 200 && $wCode < 400 && $wBody) {
                $json = json_decode($wBody, true);
                $items = $json['search_objects'] ?? [];
                foreach (array_slice($items, 0, 15) as $item) {
                    $title = $item['title'] ?? '';
                    $price = $item['price'] ?? 0;
                    $city  = $item['location']['city'] ?? '';
                    $slug  = $item['web_slug'] ?? $item['id'] ?? '';
                    $wpId  = $item['id'] ?? $slug;
                    $img   = $item['images'][0]['medium'] ?? $item['images'][0]['original'] ?? '';
                    $desc  = $item['description'] ?? '';

                    if ($title && !isset($wpSeenIds[$wpId])) {
                        $wpSeenIds[$wpId] = true;
                        $yearInfo = extractYearEx($title . ' ' . $desc, $title);
                        $results[] = [
                            'store'    => 'Wallapop',
                            'location' => ($city ?: 'Espanya') . ', Espanya',
                            'title'    => clean($title),
                            'year'     => $yearInfo['year'],
                            'year_confidence' => $yearInfo['confidence'],
                            'price'    => $price ? number_format((float)$price, 0, ',', '.') . ' EUR' : '-',
                            'link'     => $slug ? "https://es.wallapop.com/item/{$slug}" : '',
                            'image'    => $img,
                            'desc'     => clean(mb_substr($desc, 0, 150)),
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {}
    }
    scraperDone('Wallapop');
}

// ══════════════════════════════════════════════════════════════
// 13) LEBONCOIN (França) - __NEXT_DATA__
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        scraper('Leboncoin');
        $q = urlencode($searchModel . ' piano');
        $resp = fetchWithCode("https://www.leboncoin.fr/recherche?text={$q}&category=26");

        if ($resp['code'] >= 400 || !$resp['body']) {
            $scrapersRun['Leboncoin']['status'] = 'blocked';
            $scrapersRun['Leboncoin']['note'] = 'Anti-bot DataDome';
        } else {
            $body = $resp['body'];
            if (preg_match('/__NEXT_DATA__[^>]*>(.*?)<\/script>/si', $body, $m)) {
                $json = json_decode($m[1], true);
                $ads  = $json['props']['pageProps']['searchData']['ads'] ?? [];
                foreach (array_slice($ads, 0, 10) as $ad) {
                    $price = $ad['price'][0] ?? 0;
                    $title = $ad['subject'] ?? '';
                    if ($title) {
                        $yearInfo = extractYearEx($title . ' ' . ($ad['body'] ?? ''), $title);
                        $results[] = [
                            'store'    => 'Leboncoin',
                            'location' => ($ad['location']['city'] ?? '') . ', Franca',
                            'title'    => clean($title),
                            'year'     => $yearInfo['year'],
                            'year_confidence' => $yearInfo['confidence'],
                            'price'    => $price ? number_format((float)$price, 0, ',', '.') . ' EUR' : '-',
                            'link'     => $ad['url'] ?? '',
                            'image'    => $ad['images']['thumb_url'] ?? '',
                            'desc'     => clean(mb_substr($ad['body'] ?? '', 0, 150)),
                        ];
                    }
                }
            }
            scraperDone('Leboncoin');
        }
    } catch (\Throwable $e) { scraperFail('Leboncoin');}
}

// ══════════════════════════════════════════════════════════════
// 14) OLX.PL (Polònia) - HTML cards
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        scraper('OLX.pl');
        $q = urlencode($searchModel . ' pianino');
        $body = fetch("https://www.olx.pl/oferty/q-{$q}/", 15);

        if ($body) {
            if (preg_match_all('/data-cy="l-card"[^>]*id="(\d+)".*?href="(\/d\/oferta\/[^"]+)".*?<h4[^>]*>(.*?)<\/h4>.*?data-testid="ad-price"[^>]*>(.*?)<\/p>/si', $body, $cards, PREG_SET_ORDER)) {
                foreach (array_slice($cards, 0, 15) as $card) {
                    $adId    = $card[1];
                    $link    = 'https://www.olx.pl' . strtok($card[2], '?');
                    $title   = clean($card[3]);
                    $priceRaw = $card[4];
                    $price = '-';
                    if (preg_match('/([\d\s]+)\s*zł/', $priceRaw, $pm)) {
                        $price = trim($pm[1]) . ' PLN';
                    }
                    $img = '';
                    if (preg_match('/src="(https?:\/\/[^"]*apollo\.olxcdn[^"]+)"/i', $card[0], $im)) {
                        $img = $im[1];
                    }
                    $loc = '';
                    if (preg_match('/data-testid="location-date"[^>]*>(.*?)</si', $body, $lm)) {
                        $loc = clean($lm[1]);
                    }

                    if ($title) {
                        $yearInfo = extractYearEx($title, $title);
                        $results[] = [
                            'store'    => 'OLX.pl',
                            'location' => ($loc ?: 'Polònia') . ', Polònia',
                            'title'    => $title,
                            'year'     => $yearInfo['year'],
                            'year_confidence' => $yearInfo['confidence'],
                            'price'    => $price,
                            'link'     => $link,
                            'image'    => $img,
                            'desc'     => '',
                        ];
                    }
                }
            }
        }

        scraperDone('OLX.pl');
    } catch (\Throwable $e) { scraperFail('OLX.pl');}
}

// ── Diagnòstic: comptar resultats bruts per font ────────────
$rawCount = count($results);
$rawByStore = [];
foreach ($results as $r) {
    $s = $r['store'];
    $rawByStore[$s] = ($rawByStore[$s] ?? 0) + 1;
}

// ── Classificació nou/2a mà ─────────────────────────────────
foreach ($results as &$r) {
    $r['condition'] = classifyCondition($r['title'], $r['link'], $r['store'], $r['desc'] ?? '');
}
unset($r);

$beforeCondFilter = count($results);
$results = array_values(array_filter($results, fn($r) => $r['condition'] === '2a_ma'));
$afterCondFilter = count($results);

// ── Filtratge de rellevància ────────────────────────────────
$modelClean = mb_strtolower(preg_replace('/\byamaha\b/i', '', $model));
$modelCode = trim(preg_replace('/[\s\-]+/', '', $modelClean));

// Build flexible pattern: allow optional separators (-, space, .) between chars
$chars = preg_split('//u', $modelCode, -1, PREG_SPLIT_NO_EMPTY);
$flexPattern = implode('[\s\-\.]*', array_map(fn($c) => preg_quote($c, '/'), $chars));
$modelPattern = '/(?<![a-z0-9])' . $flexPattern . '(?![0-9])/i';

$beforeModelFilter = count($results);
$results = array_values(array_filter($results, function($r) use ($modelPattern) {
    if (empty($r['title']) || empty($r['link'])) return false;
    $text = mb_strtolower($r['title'] . ' ' . ($r['desc'] ?? '') . ' ' . ($r['link'] ?? ''));
    return (bool) preg_match($modelPattern, $text);
}));
$afterModelFilter = count($results);

// ── Deduplicació per link ───────────────────────────────────
$seen = [];
$results = array_values(array_filter($results, function($r) use (&$seen) {
    $key = $r['link'];
    if (isset($seen[$key])) return false;
    $seen[$key] = true;
    return true;
}));

// ── Resposta ────────────────────────────────────────────────
foreach ($scrapersRun as &$sr) { unset($sr['start']); unset($sr['count_before']); }
unset($sr);

$response = [
    'model'      => $model,
    'region'     => $region,
    'count'      => count($results),
    'results'    => array_values($results),
    'sources'    => array_values(array_unique(array_column($results, 'store'))),
    'scrapers'   => $scrapersRun,
    'debug'      => [
        'raw_total'          => $rawCount,
        'raw_by_store'       => $rawByStore,
        'after_cond_filter'  => $afterCondFilter,
        'after_model_filter' => $afterModelFilter,
        'final_count'        => count($results),
        'model_pattern'      => $modelPattern,
    ],
    'cached'     => false,
    'scraped_at' => date('c'),
];

$json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
@file_put_contents($cacheFile, $json);
echo $json;
