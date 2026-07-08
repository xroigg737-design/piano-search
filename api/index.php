<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$model  = trim($_GET['model'] ?? '');
$region = trim($_GET['region'] ?? 'espanya');

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

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
    readfile($cacheFile);
    exit;
}

// ── Helpers ─────────────────────────────────────────────────
function fetch(string $url, int $timeout = 15): ?string {
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

function clean(string $s): string {
    return trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($s, ENT_QUOTES|ENT_HTML5, 'UTF-8'))));
}

function extractYear(string $text, string $title = ''): string {
    if (preg_match('/\b(19[6-9]\d|20[0-2]\d)(?=[^0-9]|$)/', $text, $m)) return $m[1];
    $serial = extractSerial($text);
    if ($serial) return serialToYear($serial, $title) ?: '';
    return '';
}

function extractYearFromPage(string $html, string $title): string {
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $serial = extractSerial($text);
    if ($serial) {
        $year = serialToYear($serial, $title);
        if ($year) return $year;
    }
    // Look for year near piano-related keywords only
    if (preg_match('/(?:año|fabricaci|built|baujahr|bouwjaar)\s*:?\s*(19[6-9]\d|20[0-2]\d)/i', $text, $m)) {
        return $m[1];
    }
    return '';
}

function extractSerial(string $text): ?string {
    // "Serial: 1234567", "Nº serie: 1234567", "S/N: 1234567", "serienummer X1234567"
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
    $mpStores = ['Wallapop','Kleinanzeigen','Marktplaats','eBay','Leboncoin'];
    if (in_array($store, $mpStores)) return '2a_ma';
    // Specialist second-hand stores
    $usedStores = ['Art Guinardo','Pianos Low Cost','La Casa dels Pianos','Pianos Can Puig','Sinergia Music','Jorquera Pianos','Pirineus Musical',
                    'Bol Pianos','Instrumentum','EML Pianos','Hanlet','Pianos Schaeffer','Piano Fischer',
                    'Markson Pianos','Sherwood Phoenix','Nebout & Hamm','Marangi','Pianissimo','Casa Hazen',
                    "Piano's Maene",'Grand Gallery','Piano Plaza','Japan Piano Service',
                    'Hinves Pianos','Musical Princesa','Royal Pianos','Piano Importa',
                    'Rincon Musical','Musicasa','Polimusica','Musical Leones',
                    'Klavierhaus Langer','Piano.art','Anamorphose','2dehands.be',
                    'Piano Chollo','Klavier','Klavier Kreisel','Klavierhalle','Besbrode Pianos',
                    'PIANOZ','Pianoshop.fr','Quatre Mains','KlavierLoft','Scorticati','Bontempi','Klaviano'];
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

$results = [];

// ══════════════════════════════════════════════════════════════
// 1) LA CASA DELS PIANOS (Barcelona) - WordPress search
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['catalunya', 'espanya', 'europa'])) {
    try {
        $q = urlencode($searchModel);
        $body = fetch("https://lacasadelspianos.com/es/?s={$q}");

        if ($body) {
            // Find product links in search results
            if (preg_match_all('/href="(https?:\/\/lacasadelspianos\.com\/es\/pianos-item\/[^"]+)"/i', $body, $links)) {
                $productLinks = array_unique($links[1]);
                foreach (array_slice($productLinks, 0, 8) as $pLink) {
                    $pBody = fetch($pLink);
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
                        $year = extractYear($title . ' ' . $desc, $title);
                        if (!$year) $year = extractYearFromPage($pBody, $title);
                        $results[] = [
                            'store'    => 'La Casa dels Pianos',
                            'location' => 'Barcelona, Catalunya',
                            'title'    => $title,
                            'year'     => $year,
                            'price'    => $price ?: '-',
                            'link'     => $pLink,
                            'image'    => $img,
                            'desc'     => $desc,
                        ];
                    }
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 2) ART GUINARDO (Barcelona) - Crawl 2a mà category pages
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['catalunya', 'espanya', 'europa'])) {
    try {
        $agCategories = [
            'https://www.artguinardo.com/112-pianos-yamaha-verticales-segunda-mano',
            'https://www.artguinardo.com/115-pianos-yamaha-de-cola-de-segunda-mano',
        ];
        foreach ($agCategories as $agUrl) {
            $body = fetch($agUrl);
            if (!$body) continue;

            if (preg_match_all('/<article[^>]*class="[^"]*product-miniature[^"]*"[^>]*>(.*?)<\/article>/si', $body, $items)) {
                foreach ($items[1] as $item) {
                    $title = ''; $price = ''; $link = ''; $img = '';

                    if (preg_match('/<h[34][^>]*>\s*<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/si', $item, $m)) {
                        $link = $m[1];
                        $title = clean($m[2]);
                    }
                    $price = extractPrestashopPrice($item);
                    if (preg_match('/<img[^>]+(?:data-full-size-image-url|src)="([^"]+)"/i', $item, $m)) {
                        $img = $m[1];
                    }

                    if ($title && $link) {
                        $year = extractYear($title, $title);
                        $desc = 'segunda mano';
                        if (!$year) {
                            $pBody = fetch($link, 10);
                            if ($pBody) {
                                $year = extractYearFromPage($pBody, $title);
                            }
                        }
                        $results[] = [
                            'store'    => 'Art Guinardo',
                            'location' => 'Barcelona, Catalunya',
                            'title'    => $title,
                            'year'     => $year,
                            'price'    => $price ?: '-',
                            'link'     => $link,
                            'image'    => $img,
                            'desc'     => $desc ?: 'segunda mano',
                        ];
                    }
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 3) AUDENIS (Barcelona) - Crawl ocasió category
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['catalunya', 'espanya', 'europa'])) {
    try {
        $body = fetch("https://audenisbcn.com/es/317-piano-ocasion");

        if ($body) {
            if (preg_match_all('/<article[^>]*class="[^"]*product-miniature[^"]*"[^>]*>(.*?)<\/article>/si', $body, $items)) {
                foreach ($items[1] as $item) {
                    $title = ''; $price = ''; $link = ''; $img = '';

                    if (preg_match('/<h[34][^>]*>\s*<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/si', $item, $m)) {
                        $link = $m[1];
                        $title = clean($m[2]);
                    }
                    $price = extractPrestashopPrice($item);
                    if (preg_match('/<img[^>]+(?:data-full-size-image-url|src)="([^"]+)"/i', $item, $m)) {
                        $img = $m[1];
                    }

                    if ($title && $link) {
                        $year = extractYear($title, $title);
                        if (!$year) {
                            $pBody = fetch($link, 10);
                            if ($pBody) $year = extractYearFromPage($pBody, $title);
                        }
                        $results[] = [
                            'store'    => 'Audenis',
                            'location' => 'Barcelona, Catalunya',
                            'title'    => $title,
                            'year'     => $year,
                            'price'    => $price ?: '-',
                            'link'     => $link,
                            'image'    => $img,
                            'desc'     => 'ocasion',
                        ];
                    }
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 4) PIANOS LOW COST (Madrid) - Crawl renovados/ocasion categories
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['espanya', 'europa'])) {
    try {
        $plcCategories = [
            'https://www.pianoslowcost.es/7-pianos-verticales-renovados',
            'https://www.pianoslowcost.es/11-pianos-cola-renovados',
            'https://www.pianoslowcost.es/8-pianos-de-ocasion-revisados',
            'https://www.pianoslowcost.es/10-pianos-de-ocasion-revisados',
        ];
        foreach ($plcCategories as $plcUrl) {
            $body = fetch($plcUrl);
            if (!$body) continue;

            if (preg_match_all('/<article[^>]*class="[^"]*product-miniature[^"]*"[^>]*>(.*?)<\/article>/si', $body, $items)) {
                foreach ($items[1] as $item) {
                    $title = ''; $price = ''; $link = ''; $img = '';

                    if (preg_match('/<h[34][^>]*>\s*<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/si', $item, $m)) {
                        $link = $m[1];
                        $title = clean($m[2]);
                    }
                    $price = extractPrestashopPrice($item);
                    if (preg_match('/<img[^>]+(?:data-full-size-image-url|src)="([^"]+)"/i', $item, $m)) {
                        $img = $m[1];
                    }

                    if ($title && $link) {
                        $year = extractYear($title, $title);
                        if (!$year) {
                            $pBody = fetch($link, 10);
                            if ($pBody) $year = extractYearFromPage($pBody, $title);
                        }
                        $results[] = [
                            'store'    => 'Pianos Low Cost',
                            'location' => 'Madrid, Espanya',
                            'title'    => $title,
                            'year'     => $year,
                            'price'    => $price ?: '-',
                            'link'     => $link,
                            'image'    => $img,
                            'desc'     => 'renovado ocasion',
                        ];
                    }
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 5) CORRALES PIANOS (Barcelona) - Crawl category pages
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['catalunya', 'espanya', 'europa'])) {
    try {
        $categories = [
            'https://www.corralespianos.com/pianos-de-ocasion/',
        ];
        $modelSlug = strtolower(str_replace([' ', '/'], ['-', '-'], $model));

        foreach ($categories as $catUrl) {
            $body = fetch($catUrl);
            if (!$body) continue;

            // Find product links that might match
            if (preg_match_all('/href="(https?:\/\/www\.corralespianos\.com\/[^"]*' . preg_quote($modelSlug, '/') . '[^"]*)"/i', $body, $links)) {
                foreach (array_unique($links[1]) as $pLink) {
                    $pBody = fetch($pLink);
                    if (!$pBody) continue;

                    $title = ''; $price = ''; $img = '';
                    if (preg_match('/<h[12][^>]*>(.*?)<\/h[12]>/si', $pBody, $m)) {
                        $title = clean($m[1]);
                    }
                    if (preg_match('/(?:A partir de|Precio|PVP)[:\s]*([\d.,]+)\s*(?:€|EUR)/i', $pBody, $m)) {
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
                        $results[] = [
                            'store'    => 'Corrales Pianos',
                            'location' => 'Barcelona, Catalunya',
                            'title'    => $title,
                            'year'     => extractYear($title, $title),
                            'price'    => $price ?: 'Consultar',
                            'link'     => $pLink,
                            'image'    => $img,
                            'desc'     => '',
                        ];
                    }
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 6) PIANOS CAN PUIG (Mataró) - Shopify JSON API
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['catalunya', 'espanya', 'europa'])) {
    try {
        $cpCollections = [
            'https://pianoscanpuig.com/collections/pianos-de-ocasion/products.json',
            'https://pianoscanpuig.com/collections/pianos-de-re-estreno/products.json',
        ];
        foreach ($cpCollections as $cpUrl) {
            $body = fetch($cpUrl);
            if (!$body) continue;
            $json = json_decode($body, true);
            $products = $json['products'] ?? [];
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
                    $results[] = [
                        'store'    => 'Pianos Can Puig',
                        'location' => 'Mataro, Catalunya',
                        'title'    => clean($title),
                        'year'     => extractYear($title . ' ' . $desc, $title),
                        'price'    => $price ?: '-',
                        'link'     => $link,
                        'image'    => $img,
                        'desc'     => 'ocasion ' . clean($desc),
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 7) SINERGIA MUSIC (Mataró) - PrestaShop category crawl
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['catalunya', 'espanya', 'europa'])) {
    try {
        $body = fetch("https://sinergiamusic.es/392-piano-segunda-mano");

        if ($body) {
            // Extract product blocks: link + title + price
            if (preg_match_all('/href="(https:\/\/sinergiamusic\.es\/[^"]*\.html)"[^>]*>\s*<img[^>]*>/si', $body, $links, PREG_SET_ORDER)) {
                $seenLinks = [];
                foreach ($links as $lm) {
                    $pLink = $lm[1];
                    if (isset($seenLinks[$pLink])) continue;
                    $seenLinks[$pLink] = true;
                }
                // Also try structured extraction
            }

            // Extract titles from product links
            if (preg_match_all('/<a[^>]+href="(https:\/\/sinergiamusic\.es\/[^"]*\.html)"[^>]+title="([^"]+)"/si', $body, $pms, PREG_SET_ORDER)) {
                $seenSM = [];
                foreach ($pms as $pm) {
                    $pLink = $pm[1];
                    $title = clean($pm[2]);
                    if (isset($seenSM[$pLink]) || !$title) continue;
                    $seenSM[$pLink] = true;

                    // Find price near this product
                    $price = '';
                    $pos = strpos($body, $pLink);
                    if ($pos !== false) {
                        $chunk = substr($body, $pos, 2000);
                        if (preg_match('/class="price product-price">([\d\s.,]+)\s*€/i', $chunk, $pm2)) {
                            $priceVal = str_replace(' ', '', trim($pm2[1]));
                            $price = $priceVal . ' EUR';
                        }
                    }
                    // Image
                    $img = '';
                    if (preg_match('/href="' . preg_quote($pLink, '/') . '"[^>]*>\s*<img[^>]+src="([^"]+)"/si', $body, $im)) {
                        $img = $im[1];
                    }

                    if ($title) {
                        $results[] = [
                            'store'    => 'Sinergia Music',
                            'location' => 'Mataro, Catalunya',
                            'title'    => $title,
                            'year'     => extractYear($title, $title),
                            'price'    => $price ?: '-',
                            'link'     => $pLink,
                            'image'    => $img,
                            'desc'     => 'segunda mano ocasion',
                        ];
                    }
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 8) JORQUERA PIANOS (Barcelona) - WordPress text parsing
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['catalunya', 'espanya', 'europa'])) {
    try {
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
                    $year = extractYear($chunk, 'Yamaha ' . $jqModel);
                    $results[] = [
                        'store'    => 'Jorquera Pianos',
                        'location' => 'Barcelona, Catalunya',
                        'title'    => 'Yamaha ' . $jqModel,
                        'year'     => $year,
                        'price'    => $price ?: 'Consultar',
                        'link'     => $jqUrl,
                        'image'    => '',
                        'desc'     => 'reestreno segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 9) PIRINEUS MUSICAL (Reus, Tarragona) - WooCommerce
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['catalunya', 'espanya', 'europa'])) {
    try {
        $body = fetch('https://pirineusmusical.com/categoria-producto/ocasio/');
        if ($body && preg_match_all('/<li[^>]*class="[^"]*product[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
            foreach (array_slice($items[1], 0, 20) as $item) {
                $title = ''; $price = ''; $link = ''; $img = '';
                if (preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*<img/si', $item, $m)) $link = $m[1];
                if (preg_match('/<h[23][^>]*>(.*?)<\/h[23]>/si', $item, $m)) $title = clean($m[1]);
                if (preg_match('/woocommerce-Price-amount[^>]*>([\d.,]+)/si', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                } elseif (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                }
                if (preg_match('/<img[^>]+(?:data-src|src)="([^"]+)"/i', $item, $m)) $img = $m[1];
                if ($title && $link) {
                    $results[] = [
                        'store' => 'Pirineus Musical', 'location' => 'Reus, Catalunya',
                        'title' => clean($title), 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'ocasio segona ma',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 10) KLEINANZEIGEN.DE (Alemanya) - JSON-LD
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $q = urlencode($searchModel . ' piano');
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
                    $results[] = [
                        'store'    => 'Kleinanzeigen',
                        'location' => ($loc ?: 'Alemanya') . ', Alemanya',
                        'title'    => clean($title),
                        'year'     => extractYear($title . ' ' . $desc, $title),
                        'price'    => $price ?: '-',
                        'link'     => $link,
                        'image'    => $img,
                        'desc'     => clean($desc),
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 11) MARKTPLAATS.NL (Holanda) - __NEXT_DATA__
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
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
                $slug       = $l['itemId'] ?? '';
                $img        = $l['imageUrls'][0] ?? '';
                $desc       = $l['description'] ?? '';

                $priceStr = $priceCents > 0 ? number_format($priceCents / 100, 0, ',', '.') . ' EUR' : ($priceType ?: '-');
                $linkUrl  = $slug ? "https://www.marktplaats.nl/v/detail/{$slug}" : '';

                if ($title && $linkUrl) {
                    $results[] = [
                        'store'    => 'Marktplaats',
                        'location' => ($city ?: 'Holanda') . ', Holanda',
                        'title'    => clean($title),
                        'year'     => extractYear($title . ' ' . $desc, $title),
                        'price'    => $priceStr,
                        'link'     => $linkUrl,
                        'image'    => $img,
                        'desc'     => clean(mb_substr($desc, 0, 150)),
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 12) EBAY (.es i .de)
// ══════════════════════════════════════════════════════════════
$ebayDomains = [];
if (in_array($region, ['espanya', 'catalunya'])) $ebayDomains[] = 'www.ebay.es';
if ($region === 'europa') { $ebayDomains[] = 'www.ebay.es'; $ebayDomains[] = 'www.ebay.de'; }

foreach ($ebayDomains as $ebayDomain) {
    try {
        $q = urlencode($searchModel . ' piano');
        $body = fetch("https://{$ebayDomain}/sch/i.html?_nkw={$q}&_sacat=180015&LH_BIN=1&_sop=15");

        if ($body && preg_match_all('/<li[^>]*class="[^"]*s-item\s[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
            foreach (array_slice($items[1], 1, 10) as $item) {
                $title = ''; $price = ''; $link = ''; $img = '';
                if (preg_match('/class="s-item__title"[^>]*>(?:<span[^>]*>)?(.*?)(?:<\/span>)?<\//si', $item, $m)) $title = clean($m[1]);
                if (preg_match('/class="s-item__price"[^>]*>(.*?)<\/span>/si', $item, $m)) $price = clean($m[1]);
                if (preg_match('/href="(https?:\/\/www\.ebay\.[^"]*)"/', $item, $m)) $link = strtok($m[1], '?');
                if (preg_match('/<img[^>]*src="(https?:\/\/i\.ebayimg[^"]*)"/', $item, $m)) $img = $m[1];

                if ($title && mb_strlen($title) > 5 && !str_contains(strtolower($title), 'shop on ebay')) {
                    $country = str_contains($ebayDomain, '.de') ? 'Alemanya' : 'Espanya';
                    $results[] = [
                        'store'    => 'eBay',
                        'location' => $country,
                        'title'    => $title,
                        'year'     => extractYear($title, $title),
                        'price'    => $price ?: '-',
                        'link'     => $link,
                        'image'    => $img,
                        'desc'     => '',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 13) WALLAPOP (Espanya) - API JSON
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['espanya', 'catalunya'])) {
    try {
        $q = urlencode($searchModel . ' piano');
        $lat = $region === 'catalunya' ? '41.3851' : '40.4168';
        $lon = $region === 'catalunya' ? '2.1734' : '-3.7038';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => "https://api.wallapop.com/api/v3/general/search?keywords={$q}&latitude={$lat}&longitude={$lon}&filters_source=default_filters&order_by=newest",
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

        if ($wCode >= 200 && $wCode < 400 && $wBody) {
            $json = json_decode($wBody, true);
            $items = $json['search_objects'] ?? [];
            foreach (array_slice($items, 0, 12) as $item) {
                $title = $item['title'] ?? '';
                $price = $item['price'] ?? 0;
                $city  = $item['location']['city'] ?? '';
                $slug  = $item['web_slug'] ?? $item['id'] ?? '';
                $img   = $item['images'][0]['medium'] ?? $item['images'][0]['original'] ?? '';
                $desc  = $item['description'] ?? '';

                if ($title) {
                    $results[] = [
                        'store'    => 'Wallapop',
                        'location' => ($city ?: 'Espanya') . ', Espanya',
                        'title'    => clean($title),
                        'year'     => extractYear($title . ' ' . $desc, $title),
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

// ══════════════════════════════════════════════════════════════
// 14) LEBONCOIN (França) - __NEXT_DATA__
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $q = urlencode($searchModel . ' piano');
        $body = fetch("https://www.leboncoin.fr/recherche?text={$q}&category=26");

        if ($body && preg_match('/__NEXT_DATA__[^>]*>(.*?)<\/script>/si', $body, $m)) {
            $json = json_decode($m[1], true);
            $ads  = $json['props']['pageProps']['searchData']['ads'] ?? [];
            foreach (array_slice($ads, 0, 10) as $ad) {
                $price = $ad['price'][0] ?? 0;
                $title = $ad['subject'] ?? '';
                if ($title) {
                    $results[] = [
                        'store'    => 'Leboncoin',
                        'location' => ($ad['location']['city'] ?? '') . ', Franca',
                        'title'    => clean($title),
                        'year'     => extractYear($title . ' ' . ($ad['body'] ?? ''), $title),
                        'price'    => $price ? number_format((float)$price, 0, ',', '.') . ' EUR' : '-',
                        'link'     => $ad['url'] ?? '',
                        'image'    => $ad['images']['thumb_url'] ?? '',
                        'desc'     => clean(mb_substr($ad['body'] ?? '', 0, 150)),
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 15) BOL PIANOS (Holanda/Belgica) - Shopify JSON
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch('https://bolpianos.com/en/collections/tweedehands-pianos/products.json?limit=250');
        if ($body) {
            $json = json_decode($body, true);
            foreach (($json['products'] ?? []) as $p) {
                $title = $p['title'] ?? '';
                $price = '';
                if (!empty($p['variants'][0]['price'])) {
                    $val = (float)$p['variants'][0]['price'];
                    $price = $val > 0 ? number_format($val, 0, ',', '.') . ' EUR' : '-';
                }
                $img = $p['images'][0]['src'] ?? '';
                $handle = $p['handle'] ?? '';
                $link = $handle ? "https://bolpianos.com/en/products/{$handle}" : '';
                $desc = mb_substr(strip_tags($p['body_html'] ?? ''), 0, 150);
                if ($title && $link) {
                    $results[] = [
                        'store' => 'Bol Pianos', 'location' => 'Holanda/Belgica',
                        'title' => clean($title), 'year' => extractYear($title . ' ' . $desc, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'tweedehands segunda mano ' . clean($desc),
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 16) INSTRUMENTUM.CH (Suissa) - Shopify JSON
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch('https://www.instrumentum.ch/collections/gebrauchtes-klavier-kaufen-occasion-klaviere/products.json?limit=250');
        if ($body) {
            $json = json_decode($body, true);
            foreach (($json['products'] ?? []) as $p) {
                $title = $p['title'] ?? '';
                $price = '';
                if (!empty($p['variants'][0]['price'])) {
                    $val = (float)$p['variants'][0]['price'];
                    $price = $val > 0 ? number_format($val, 0, ',', '.') . ' CHF' : '-';
                }
                $img = $p['images'][0]['src'] ?? '';
                $handle = $p['handle'] ?? '';
                $link = $handle ? "https://www.instrumentum.ch/products/{$handle}" : '';
                $desc = mb_substr(strip_tags($p['body_html'] ?? ''), 0, 150);
                if ($title && $link) {
                    $results[] = [
                        'store' => 'Instrumentum', 'location' => 'Suissa',
                        'title' => clean($title), 'year' => extractYear($title . ' ' . $desc, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'gebraucht occasion ' . clean($desc),
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 17) EML PIANOS LYON (Franca) - PrestaShop
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch("https://www.pianos-lyon.com/6-pianos-occasion");
        if ($body) {
            if (preg_match_all('/<article[^>]*class="[^"]*product-miniature[^"]*"[^>]*>(.*?)<\/article>/si', $body, $items)) {
                foreach ($items[1] as $item) {
                    $title = ''; $price = ''; $link = ''; $img = '';
                    if (preg_match('/<h[34][^>]*>\s*<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/si', $item, $m)) {
                        $link = $m[1]; $title = clean($m[2]);
                    }
                    $price = extractPrestashopPrice($item);
                    if (preg_match('/<img[^>]+(?:data-full-size-image-url|src)="([^"]+)"/i', $item, $m)) {
                        $img = $m[1];
                    }
                    if ($title && $link) {
                        $results[] = [
                            'store' => 'EML Pianos', 'location' => 'Lyon, Franca',
                            'title' => clean($title), 'year' => extractYear($title, $title),
                            'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                            'desc' => 'occasion segunda mano',
                        ];
                    }
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 18) HANLET (Brusselles, Belgica) - PrestaShop
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch("https://hanlet.be/en/14-second-hand");
        if ($body) {
            if (preg_match_all('/<article[^>]*class="[^"]*product-miniature[^"]*"[^>]*>(.*?)<\/article>/si', $body, $items)) {
                foreach ($items[1] as $item) {
                    $title = ''; $price = ''; $link = ''; $img = '';
                    if (preg_match('/<h[34][^>]*>\s*<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/si', $item, $m)) {
                        $link = $m[1]; $title = clean($m[2]);
                    }
                    $price = extractPrestashopPrice($item);
                    if (preg_match('/<img[^>]+(?:data-full-size-image-url|src)="([^"]+)"/i', $item, $m)) {
                        $img = $m[1];
                    }
                    if ($title && $link) {
                        $results[] = [
                            'store' => 'Hanlet', 'location' => 'Brusselles, Belgica',
                            'title' => clean($title), 'year' => extractYear($title, $title),
                            'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                            'desc' => 'second hand segunda mano',
                        ];
                    }
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 19) PIANOS SCHAEFFER (Franca/Luxemburg) - PrestaShop
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $psPages = [
            'https://pianos-schaeffer.com/en/667-used-upright-pianos',
            'https://pianos-schaeffer.com/en/711-other-used-premium-pianos',
        ];
        foreach ($psPages as $psUrl) {
            $body = fetch($psUrl);
            if (!$body) continue;
            if (preg_match_all('/<article[^>]*class="[^"]*product-miniature[^"]*"[^>]*>(.*?)<\/article>/si', $body, $items)) {
                foreach ($items[1] as $item) {
                    $title = ''; $price = ''; $link = ''; $img = '';
                    if (preg_match('/<h[34][^>]*>\s*<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/si', $item, $m)) {
                        $link = $m[1]; $title = clean($m[2]);
                    }
                    $price = extractPrestashopPrice($item);
                    if (preg_match('/<img[^>]+(?:data-full-size-image-url|src)="([^"]+)"/i', $item, $m)) {
                        $img = $m[1];
                    }
                    if ($title && $link) {
                        $results[] = [
                            'store' => 'Pianos Schaeffer', 'location' => 'Nancy, Franca',
                            'title' => clean($title), 'year' => extractYear($title, $title),
                            'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                            'desc' => 'used occasion segunda mano',
                        ];
                    }
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 20) PIANO FISCHER (Alemanya) - WooCommerce
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $pfPages = [
            'https://www.piano-fischer.de/kategorie/gebrauchte/klavier/',
            'https://www.piano-fischer.de/kategorie/gebrauchte/fluegel-gebrauchte/',
        ];
        foreach ($pfPages as $pfUrl) {
            $body = fetch($pfUrl);
            if (!$body) continue;
            if (preg_match_all('/<li[^>]*class="[^"]*product[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
                foreach ($items[1] as $item) {
                    $title = ''; $price = ''; $link = ''; $img = '';
                    if (preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*<img/si', $item, $m)) {
                        $link = $m[1];
                    }
                    if (preg_match('/<h[23][^>]*>(.*?)<\/h[23]>/si', $item, $m)) {
                        $title = clean($m[1]);
                    }
                    if (preg_match('/woocommerce-Price-amount[^>]*>([\d\s.,]+)/si', $item, $m)) {
                        $price = str_replace(' ', '', trim($m[1])) . ' EUR';
                    } elseif (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $item, $m)) {
                        $price = trim($m[1]) . ' EUR';
                    }
                    if (preg_match('/<img[^>]+(?:data-src|src)="([^"]+)"/i', $item, $m)) {
                        $img = $m[1];
                    }
                    if ($title && $link) {
                        $results[] = [
                            'store' => 'Piano Fischer', 'location' => 'Alemanya',
                            'title' => clean($title), 'year' => extractYear($title, $title),
                            'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                            'desc' => 'gebraucht segunda mano',
                        ];
                    }
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 21) MARKSON PIANOS (Londres, UK) - WooCommerce
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch('https://marksonpianos.com/product-category/pre-owned-pianos/?per_page=96');
        if ($body && preg_match_all('/<li[^>]*class="[^"]*product[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
            foreach (array_slice($items[1], 0, 30) as $item) {
                $title = ''; $price = ''; $link = ''; $img = '';
                if (preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*<img/si', $item, $m)) {
                    $link = $m[1];
                }
                if (preg_match('/<h[23][^>]*>(.*?)<\/h[23]>/si', $item, $m)) {
                    $title = clean($m[1]);
                }
                if (preg_match('/woocommerce-Price-amount[^>]*>.*?(?:£|&#163;|&pound;)\s*([\d.,]+)/si', $item, $m)) {
                    $price = trim($m[1]) . ' GBP';
                } elseif (preg_match('/(?:£|GBP)\s*([\d.,]+)/i', $item, $m)) {
                    $price = trim($m[1]) . ' GBP';
                }
                if (preg_match('/<img[^>]+(?:data-src|src)="([^"]+)"/i', $item, $m)) {
                    $img = $m[1];
                }
                if ($title && $link) {
                    $results[] = [
                        'store' => 'Markson Pianos', 'location' => 'Londres, UK',
                        'title' => clean($title), 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'pre-owned used segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 22) SHERWOOD PHOENIX (UK) - WooCommerce
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch('https://sherwoodphoenix.co.uk/product-category/pianos/all-used-pianos/');
        if ($body && preg_match_all('/<li[^>]*class="[^"]*product[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
            foreach (array_slice($items[1], 0, 30) as $item) {
                $title = ''; $price = ''; $link = ''; $img = '';
                if (preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*<img/si', $item, $m)) {
                    $link = $m[1];
                }
                if (preg_match('/<h[23][^>]*>(.*?)<\/h[23]>/si', $item, $m)) {
                    $title = clean($m[1]);
                }
                if (preg_match('/woocommerce-Price-amount[^>]*>.*?(?:£|&#163;|&pound;)\s*([\d.,]+)/si', $item, $m)) {
                    $price = trim($m[1]) . ' GBP';
                } elseif (preg_match('/(?:£|GBP)\s*([\d.,]+)/i', $item, $m)) {
                    $price = trim($m[1]) . ' GBP';
                }
                if (preg_match('/<img[^>]+(?:data-src|src)="([^"]+)"/i', $item, $m)) {
                    $img = $m[1];
                }
                if ($title && $link) {
                    $results[] = [
                        'store' => 'Sherwood Phoenix', 'location' => 'UK',
                        'title' => clean($title), 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'used pre-owned segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 23) NEBOUT & HAMM (Paris, Franca) - WooCommerce
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch('https://nebout-hamm.com/type-de-produit/acoustique-occasion/');
        if ($body && preg_match_all('/<li[^>]*class="[^"]*product[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
            foreach (array_slice($items[1], 0, 20) as $item) {
                $title = ''; $price = ''; $link = ''; $img = '';
                if (preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*<img/si', $item, $m)) {
                    $link = $m[1];
                }
                if (preg_match('/<h[23][^>]*>(.*?)<\/h[23]>/si', $item, $m)) {
                    $title = clean($m[1]);
                }
                if (preg_match('/woocommerce-Price-amount[^>]*>([\d\s.,]+)/si', $item, $m)) {
                    $price = str_replace([' ', "\xc2\xa0"], '', trim($m[1])) . ' EUR';
                } elseif (preg_match('/([\d\s.,]+)\s*(?:€|EUR)/i', $item, $m)) {
                    $price = str_replace(' ', '', trim($m[1])) . ' EUR';
                }
                if (preg_match('/<img[^>]+(?:data-src|src)="([^"]+)"/i', $item, $m)) {
                    $img = $m[1];
                }
                if ($title && $link) {
                    $results[] = [
                        'store' => 'Nebout & Hamm', 'location' => 'Paris, Franca',
                        'title' => clean($title), 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'occasion segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 24) MARANGI (Italia) - WooCommerce
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch('https://www.marangi.it/categorie/pianoforti-usati/');
        if ($body && preg_match_all('/<li[^>]*class="[^"]*product[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
            foreach ($items[1] as $item) {
                $title = ''; $price = ''; $link = ''; $img = '';
                if (preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*<img/si', $item, $m)) {
                    $link = $m[1];
                }
                if (preg_match('/<h[23][^>]*>(.*?)<\/h[23]>/si', $item, $m)) {
                    $title = clean($m[1]);
                }
                if (preg_match('/woocommerce-Price-amount[^>]*>([\d.,]+)/si', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                } elseif (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                }
                if (preg_match('/<img[^>]+(?:data-src|src)="([^"]+)"/i', $item, $m)) {
                    $img = $m[1];
                }
                if ($title && $link) {
                    $results[] = [
                        'store' => 'Marangi', 'location' => 'Italia',
                        'title' => clean($title), 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'usato segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 25) PIANISSIMO (Madrid) - WooCommerce search
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['espanya', 'europa'])) {
    try {
        $q = urlencode($searchModel);
        $body = fetch("https://www.pianisimo.es/?s={$q}&post_type=product");
        if ($body && preg_match_all('/<li[^>]*class="[^"]*product[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
            foreach (array_slice($items[1], 0, 12) as $item) {
                $title = ''; $price = ''; $link = ''; $img = '';
                if (preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*<img/si', $item, $m)) {
                    $link = $m[1];
                }
                if (preg_match('/<h[23][^>]*>(.*?)<\/h[23]>/si', $item, $m)) {
                    $title = clean($m[1]);
                }
                if (preg_match('/woocommerce-Price-amount[^>]*>([\d.,]+)/si', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                } elseif (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                }
                if (preg_match('/<img[^>]+(?:data-src|src)="([^"]+)"/i', $item, $m)) {
                    $img = $m[1];
                }
                if ($title && $link) {
                    $results[] = [
                        'store' => 'Pianissimo', 'location' => 'Madrid, Espanya',
                        'title' => clean($title), 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 26) CASA HAZEN (Madrid) - WooCommerce search
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['espanya', 'europa'])) {
    try {
        $q = urlencode($searchModel);
        $body = fetch("https://www.casahazen.com/?s={$q}&post_type=product");
        if ($body && preg_match_all('/<li[^>]*class="[^"]*product[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
            foreach (array_slice($items[1], 0, 12) as $item) {
                $title = ''; $price = ''; $link = ''; $img = '';
                if (preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*<img/si', $item, $m)) {
                    $link = $m[1];
                }
                if (preg_match('/<h[23][^>]*>(.*?)<\/h[23]>/si', $item, $m)) {
                    $title = clean($m[1]);
                }
                if (preg_match('/woocommerce-Price-amount[^>]*>([\d.,]+)/si', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                } elseif (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                }
                if (preg_match('/<img[^>]+(?:data-src|src)="([^"]+)"/i', $item, $m)) {
                    $img = $m[1];
                }
                if ($title && $link) {
                    $results[] = [
                        'store' => 'Casa Hazen', 'location' => 'Madrid, Espanya',
                        'title' => clean($title), 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 27) HINVES PIANOS (Madrid/Granada/Getxo) - WooCommerce
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['espanya', 'europa'])) {
    try {
        $body = fetch('https://www.hinfrancves.com/piano/condition/reestreno/');
        if ($body && preg_match_all('/<li[^>]*class="[^"]*product[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
            foreach (array_slice($items[1], 0, 20) as $item) {
                $title = ''; $price = ''; $link = ''; $img = '';
                if (preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*<img/si', $item, $m)) {
                    $link = $m[1];
                }
                if (preg_match('/<h[23][^>]*>(.*?)<\/h[23]>/si', $item, $m)) {
                    $title = clean($m[1]);
                }
                if (preg_match('/woocommerce-Price-amount[^>]*>([\d.,]+)/si', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                } elseif (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                }
                if (preg_match('/<img[^>]+(?:data-src|src)="([^"]+)"/i', $item, $m)) {
                    $img = $m[1];
                }
                if ($title && $link) {
                    $results[] = [
                        'store' => 'Hinves Pianos', 'location' => 'Madrid, Espanya',
                        'title' => clean($title), 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'reestreno segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 28) MUSICAL PRINCESA (Madrid) - PrestaShop
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['espanya', 'europa'])) {
    try {
        $mpPages = [
            'https://www.musicalprinces.es/222-verticales-usados',
            'https://www.musicalprinces.es/224-cola-usados',
        ];
        foreach ($mpPages as $mpUrl) {
            $body = fetch($mpUrl);
            if (!$body) continue;
            if (preg_match_all('/<article[^>]*class="[^"]*product-miniature[^"]*"[^>]*>(.*?)<\/article>/si', $body, $items)) {
                foreach ($items[1] as $item) {
                    $title = ''; $price = ''; $link = ''; $img = '';
                    if (preg_match('/<h[34][^>]*>\s*<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/si', $item, $m)) {
                        $link = $m[1]; $title = clean($m[2]);
                    }
                    $price = extractPrestashopPrice($item);
                    if (preg_match('/<img[^>]+(?:data-full-size-image-url|src)="([^"]+)"/i', $item, $m)) {
                        $img = $m[1];
                    }
                    if ($title && $link) {
                        $results[] = [
                            'store' => 'Musical Princesa', 'location' => 'Madrid, Espanya',
                            'title' => clean($title), 'year' => extractYear($title, $title),
                            'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                            'desc' => 'segunda mano usado',
                        ];
                    }
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 29) ROYAL PIANOS (Malaga/Sevilla/Oviedo) - WooCommerce
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['espanya', 'europa'])) {
    try {
        $body = fetch('https://royalpianos.com/categoria-producto/pianos-de-ocasion/');
        if ($body && preg_match_all('/<li[^>]*class="[^"]*product[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
            foreach (array_slice($items[1], 0, 20) as $item) {
                $title = ''; $price = ''; $link = ''; $img = '';
                if (preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*<img/si', $item, $m)) {
                    $link = $m[1];
                }
                if (preg_match('/<h[23][^>]*>(.*?)<\/h[23]>/si', $item, $m)) {
                    $title = clean($m[1]);
                }
                if (preg_match('/woocommerce-Price-amount[^>]*>([\d.,]+)/si', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                } elseif (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                }
                if (preg_match('/<img[^>]+(?:data-src|src)="([^"]+)"/i', $item, $m)) {
                    $img = $m[1];
                }
                if ($title && $link) {
                    $results[] = [
                        'store' => 'Royal Pianos', 'location' => 'Malaga, Espanya',
                        'title' => clean($title), 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'ocasion segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 30) PIANO IMPORTA (Valencia) - WooCommerce
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['espanya', 'europa'])) {
    try {
        $body = fetch('https://pianoimporta.com/pianos-de-ocasion/');
        if ($body && preg_match_all('/<li[^>]*class="[^"]*product[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
            foreach (array_slice($items[1], 0, 20) as $item) {
                $title = ''; $price = ''; $link = ''; $img = '';
                if (preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*<img/si', $item, $m)) {
                    $link = $m[1];
                }
                if (preg_match('/<h[23][^>]*>(.*?)<\/h[23]>/si', $item, $m)) {
                    $title = clean($m[1]);
                }
                if (preg_match('/woocommerce-Price-amount[^>]*>([\d.,]+)/si', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                } elseif (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                }
                if (preg_match('/<img[^>]+(?:data-src|src)="([^"]+)"/i', $item, $m)) {
                    $img = $m[1];
                }
                if ($title && $link) {
                    $results[] = [
                        'store' => 'Piano Importa', 'location' => 'Valencia, Espanya',
                        'title' => clean($title), 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'ocasion segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 31) PIRINEUS MUSICAL (Reus, Tarragona) - WooCommerce
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['catalunya', 'espanya', 'europa'])) {
    try {
        $pmUrls = [
            'https://www.pirineusmusical.com/categoria-producto/ocasio/',
            'https://www.pirineusmusical.com/categoria-producto/pianos-acustics/',
        ];
        foreach ($pmUrls as $pmUrl) {
            $body = fetch($pmUrl);
            if (!$body || !preg_match_all('/<li[^>]*class="[^"]*product[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) continue;
            foreach (array_slice($items[1], 0, 20) as $item) {
                $title = ''; $price = ''; $link = ''; $img = '';
                if (preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*<img/si', $item, $m)) $link = $m[1];
                if (preg_match('/<h[23][^>]*>(.*?)<\/h[23]>/si', $item, $m)) $title = clean($m[1]);
                if (preg_match('/woocommerce-Price-amount[^>]*>([\d.,]+)/si', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                } elseif (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                }
                if (preg_match('/<img[^>]+(?:data-src|src)="([^"]+)"/i', $item, $m)) $img = $m[1];
                if ($title && $link) {
                    $results[] = [
                        'store' => 'Pirineus Musical', 'location' => 'Reus, Catalunya',
                        'title' => clean($title), 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'ocasio segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 32) RINCON MUSICAL (Madrid) - WooCommerce
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['espanya', 'europa'])) {
    try {
        $body = fetch('https://www.rinconmusical.es/categoria-producto/pianos-y-teclados/pianos-acusticos/pianos-de-segunda-mano/');
        if ($body && preg_match_all('/<li[^>]*class="[^"]*product[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
            foreach (array_slice($items[1], 0, 20) as $item) {
                $title = ''; $price = ''; $link = ''; $img = '';
                if (preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*<img/si', $item, $m)) {
                    $link = $m[1];
                }
                if (preg_match('/<h[23][^>]*>(.*?)<\/h[23]>/si', $item, $m)) {
                    $title = clean($m[1]);
                }
                if (preg_match('/woocommerce-Price-amount[^>]*>([\d.,]+)/si', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                } elseif (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                }
                if (preg_match('/<img[^>]+(?:data-src|src)="([^"]+)"/i', $item, $m)) {
                    $img = $m[1];
                }
                if ($title && $link) {
                    $results[] = [
                        'store' => 'Rincon Musical', 'location' => 'Madrid, Espanya',
                        'title' => clean($title), 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 32) MUSICASA TIENDAS (Palma/Ibiza/Menorca) - PrestaShop
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['espanya', 'europa'])) {
    try {
        $body = fetch('https://www.musicasatiendas.com/705-pianos-de-ocasion-y-usados');
        if ($body) {
            if (preg_match_all('/<article[^>]*class="[^"]*product-miniature[^"]*"[^>]*>(.*?)<\/article>/si', $body, $items)) {
                foreach ($items[1] as $item) {
                    $title = ''; $price = ''; $link = ''; $img = '';
                    if (preg_match('/<h[34][^>]*>\s*<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/si', $item, $m)) {
                        $link = $m[1]; $title = clean($m[2]);
                    }
                    $price = extractPrestashopPrice($item);
                    if (preg_match('/<img[^>]+(?:data-full-size-image-url|src)="([^"]+)"/i', $item, $m)) {
                        $img = $m[1];
                    }
                    if ($title && $link) {
                        $results[] = [
                            'store' => 'Musicasa', 'location' => 'Palma, Espanya',
                            'title' => clean($title), 'year' => extractYear($title, $title),
                            'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                            'desc' => 'ocasion usado segunda mano',
                        ];
                    }
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 33) POLIMUSICA (Madrid) - WooCommerce
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['espanya', 'europa'])) {
    try {
        $body = fetch('https://polimusica.es/categoria-producto/yamaha/pianos-de-ocasion/');
        if ($body && preg_match_all('/<li[^>]*class="[^"]*product[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
            foreach ($items[1] as $item) {
                $title = ''; $price = ''; $link = ''; $img = '';
                if (preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*<img/si', $item, $m)) {
                    $link = $m[1];
                }
                if (preg_match('/<h[23][^>]*>(.*?)<\/h[23]>/si', $item, $m)) {
                    $title = clean($m[1]);
                }
                if (preg_match('/woocommerce-Price-amount[^>]*>([\d.,]+)/si', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                }
                if (preg_match('/<img[^>]+(?:data-src|src)="([^"]+)"/i', $item, $m)) {
                    $img = $m[1];
                }
                if ($title && $link) {
                    $results[] = [
                        'store' => 'Polimusica', 'location' => 'Madrid, Espanya',
                        'title' => clean($title), 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'ocasion segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 34) MUSICAL LEONES (Granada) - WooCommerce
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['espanya', 'europa'])) {
    try {
        $body = fetch('https://musicalleones.com/index.php/categoria-producto/pianos-restaurados/');
        if ($body && preg_match_all('/<li[^>]*class="[^"]*product[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
            foreach ($items[1] as $item) {
                $title = ''; $price = ''; $link = ''; $img = '';
                if (preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*<img/si', $item, $m)) {
                    $link = $m[1];
                }
                if (preg_match('/<h[23][^>]*>(.*?)<\/h[23]>/si', $item, $m)) {
                    $title = clean($m[1]);
                }
                if (preg_match('/woocommerce-Price-amount[^>]*>([\d.,]+)/si', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                }
                if (preg_match('/<img[^>]+(?:data-src|src)="([^"]+)"/i', $item, $m)) {
                    $img = $m[1];
                }
                if ($title && $link) {
                    $results[] = [
                        'store' => 'Musical Leones', 'location' => 'Granada, Espanya',
                        'title' => clean($title), 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'restaurado segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 35) KLAVIERHAUS LANGER (Austria) - Shopify JSON
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch('https://klavierhaus-langer.at/collections/all/products.json?limit=250');
        if ($body) {
            $json = json_decode($body, true);
            foreach (($json['products'] ?? []) as $p) {
                $title = $p['title'] ?? '';
                $price = '';
                if (!empty($p['variants'][0]['price'])) {
                    $val = (float)$p['variants'][0]['price'];
                    $price = $val > 0 ? number_format($val, 0, ',', '.') . ' EUR' : '-';
                }
                $img = $p['images'][0]['src'] ?? '';
                $handle = $p['handle'] ?? '';
                $link = $handle ? "https://klavierhaus-langer.at/products/{$handle}" : '';
                $desc = mb_substr(strip_tags($p['body_html'] ?? ''), 0, 150);
                if ($title && $link) {
                    $results[] = [
                        'store' => 'Klavierhaus Langer', 'location' => 'Austria',
                        'title' => clean($title), 'year' => extractYear($title . ' ' . $desc, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'gebraucht segunda mano ' . clean($desc),
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 36) PIANO.ART (Innsbruck, Austria) - WooCommerce
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch('https://piano.art/product-category/gebrauchte-klaviere/');
        if ($body && preg_match_all('/<li[^>]*class="[^"]*product[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
            foreach ($items[1] as $item) {
                $title = ''; $price = ''; $link = ''; $img = '';
                if (preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*<img/si', $item, $m)) {
                    $link = $m[1];
                }
                if (preg_match('/<h[23][^>]*>(.*?)<\/h[23]>/si', $item, $m)) {
                    $title = clean($m[1]);
                }
                if (preg_match('/woocommerce-Price-amount[^>]*>([\d\s.,]+)/si', $item, $m)) {
                    $price = str_replace([' ', "\xc2\xa0"], '', trim($m[1])) . ' EUR';
                }
                if (preg_match('/<img[^>]+(?:data-src|src)="([^"]+)"/i', $item, $m)) {
                    $img = $m[1];
                }
                if ($title && $link) {
                    $results[] = [
                        'store' => 'Piano.art', 'location' => 'Innsbruck, Austria',
                        'title' => clean($title), 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'gebraucht segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 37) ANAMORPHOSE (Nantes, Franca) - PrestaShop
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch('https://www.anamorphose-pianos.fr/12-pianos-occasion');
        if ($body) {
            if (preg_match_all('/<article[^>]*class="[^"]*product-miniature[^"]*"[^>]*>(.*?)<\/article>/si', $body, $items)) {
                foreach ($items[1] as $item) {
                    $title = ''; $price = ''; $link = ''; $img = '';
                    if (preg_match('/<h[34][^>]*>\s*<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/si', $item, $m)) {
                        $link = $m[1]; $title = clean($m[2]);
                    }
                    $price = extractPrestashopPrice($item);
                    if (preg_match('/<img[^>]+(?:data-full-size-image-url|src)="([^"]+)"/i', $item, $m)) {
                        $img = $m[1];
                    }
                    if ($title && $link) {
                        $results[] = [
                            'store' => 'Anamorphose', 'location' => 'Nantes, Franca',
                            'title' => clean($title), 'year' => extractYear($title, $title),
                            'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                            'desc' => 'occasion segunda mano',
                        ];
                    }
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 38) 2DEHANDS.BE (Belgica) - Similar a Marktplaats
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
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
                $slug       = $l['itemId'] ?? '';
                $img        = $l['imageUrls'][0] ?? '';
                $desc       = $l['description'] ?? '';

                $priceStr = $priceCents > 0 ? number_format($priceCents / 100, 0, ',', '.') . ' EUR' : ($priceType ?: '-');
                $linkUrl  = $slug ? "https://www.2dehands.be/v/detail/{$slug}" : '';

                if ($title && $linkUrl) {
                    $results[] = [
                        'store'    => '2dehands.be',
                        'location' => ($city ?: 'Belgica') . ', Belgica',
                        'title'    => clean($title),
                        'year'     => extractYear($title . ' ' . $desc, $title),
                        'price'    => $priceStr,
                        'link'     => $linkUrl,
                        'image'    => $img,
                        'desc'     => clean(mb_substr($desc, 0, 150)),
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 39) PIANO'S MAENE (Belgica) - Magento
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch('https://www.maene.be/en_BE/all-pianos/second-hand-pianos?product_list_limit=96');
        if ($body) {
            if (preg_match_all('/<li[^>]*class="[^"]*product-item[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
                foreach (array_slice($items[1], 0, 40) as $item) {
                    $title = ''; $price = ''; $link = ''; $img = '';
                    if (preg_match('/product-item-link[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/si', $item, $m)) {
                        $link = trim($m[1]);
                        $title = clean($m[2]);
                    }
                    if (preg_match('/price-wrapper[^>]*>.*?([\d.,]+)/si', $item, $m)) {
                        $price = trim($m[1]) . ' EUR';
                    } elseif (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $item, $m)) {
                        $price = trim($m[1]) . ' EUR';
                    }
                    if (preg_match('/<img[^>]+src="([^"]+)"/i', $item, $m)) {
                        $img = $m[1];
                    }
                    if ($title && $link) {
                        $results[] = [
                            'store' => "Piano's Maene", 'location' => 'Belgica',
                            'title' => clean($title), 'year' => extractYear($title, $title),
                            'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                            'desc' => 'second hand segunda mano',
                        ];
                    }
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 40) GRAND GALLERY (Japo) - Export catalog
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['japo'])) {
    try {
        $body = fetch('https://export.grandg.com/en/yamaha-piano/', 20);
        if ($body) {
            if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $body, $rows)) {
                foreach (array_slice($rows[1], 0, 50) as $row) {
                    $tds = [];
                    if (preg_match_all('/<td[^>]*>(.*?)<\/td>/si', $row, $cells)) {
                        $tds = array_map(fn($c) => trim(strip_tags($c)), $cells[1]);
                    }
                    if (count($tds) < 2) continue;
                    $title = clean($tds[0]);
                    if (mb_strlen($title) < 2) continue;
                    $price = '';
                    foreach ($tds as $td) {
                        $tdClean = str_replace(',', '', $td);
                        if (preg_match('/^[\d]+$/', $tdClean) && (int)$tdClean > 50000) {
                            $price = number_format((int)$tdClean, 0, ',', ',') . ' JPY';
                            break;
                        }
                    }
                    $link = '';
                    if (preg_match('/href="([^"]+)"/i', $row, $m)) {
                        $link = $m[1];
                        if (!str_starts_with($link, 'http')) $link = 'https://export.grandg.com' . $link;
                    }
                    $results[] = [
                        'store' => 'Grand Gallery', 'location' => 'Aichi, Japo',
                        'title' => $title, 'year' => extractYear($title, $title),
                        'price' => $price ?: '-',
                        'link' => $link ?: 'https://export.grandg.com/en/yamaha-piano/',
                        'image' => '', 'desc' => 'used export segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 41) PIANO PLAZA (Tokyo, Japo)
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['japo'])) {
    try {
        $q = urlencode($model);
        $body = fetch("https://www.pianoplaza.com/search?q={$q}", 15);
        if ($body && preg_match_all('/<div[^>]*class="[^"]*product-card[^"]*"[^>]*>(.*?)<\/div>\s*<\/div>/si', $body, $items)) {
            foreach (array_slice($items[1], 0, 12) as $item) {
                $title = ''; $price = ''; $link = ''; $img = '';
                if (preg_match('/<a[^>]+href="([^"]+)"/i', $item, $m)) {
                    $link = $m[1];
                    if (!str_starts_with($link, 'http')) $link = 'https://www.pianoplaza.com' . $link;
                }
                if (preg_match('/<(?:h[234]|span|div)[^>]*class="[^"]*(?:title|name)[^"]*"[^>]*>(.*?)<\//si', $item, $m)) {
                    $title = clean($m[1]);
                } elseif (preg_match('/<a[^>]*>(.*?)<\/a>/si', $item, $m)) {
                    $title = clean($m[1]);
                }
                if (preg_match('/([\d,]+)\s*(?:JPY|¥|円)/i', $item, $m)) {
                    $price = trim($m[1]) . ' JPY';
                }
                if (preg_match('/<img[^>]+src="([^"]+)"/i', $item, $m)) {
                    $img = $m[1];
                }
                if ($title && mb_strlen($title) > 2) {
                    $results[] = [
                        'store' => 'Piano Plaza', 'location' => 'Tokyo, Japo',
                        'title' => $title, 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link ?: 'https://www.pianoplaza.com',
                        'image' => $img, 'desc' => 'used segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 42) JAPAN PIANO SERVICE
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['japo'])) {
    try {
        $body = fetch('https://www.japanpianoservice.com/stock/', 20);
        if ($body) {
            if (preg_match_all('/<(?:tr|div|li)[^>]*>([^<]*yamaha[^<]*(?:<[^>]+>[^<]*)*)<\/(?:tr|div|li)>/si', $body, $rows)) {
                $jpseen = [];
                foreach (array_slice($rows[0], 0, 20) as $row) {
                    $title = clean(strip_tags($row));
                    if (mb_strlen($title) < 5 || mb_strlen($title) > 200 || isset($jpseen[$title])) continue;
                    $jpseen[$title] = true;
                    $price = '';
                    if (preg_match('/([\d,]+)\s*(?:JPY|¥|yen|円)/i', $row, $m)) {
                        $price = trim($m[1]) . ' JPY';
                    }
                    $link = '';
                    if (preg_match('/href="([^"]+)"/i', $row, $m)) {
                        $link = $m[1];
                        if (!str_starts_with($link, 'http')) $link = 'https://www.japanpianoservice.com' . $link;
                    }
                    $results[] = [
                        'store' => 'Japan Piano Service', 'location' => 'Japo',
                        'title' => $title, 'year' => extractYear($title, $title),
                        'price' => $price ?: '-',
                        'link' => $link ?: 'https://www.japanpianoservice.com/stock/',
                        'image' => '', 'desc' => 'used export segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 43) PIANO CHOLLO (Ontinyent, Valencia) - Custom PHP
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['espanya', 'europa'])) {
    try {
        $pcUrls = [
            'https://www.pianochollo.com/pianos-verticales/pianos-renovados',
            'https://www.pianochollo.com/pianos-de-cola/pianos-renovados',
        ];
        foreach ($pcUrls as $pcUrl) {
            $body = fetch($pcUrl);
            if (!$body) continue;
            if (preg_match_all('/<a[^>]+href="(https?:\/\/www\.pianochollo\.com\/[^"]*\d+_[^"]+)"[^>]*>(.*?)<\/a>/si', $body, $links, PREG_SET_ORDER)) {
                foreach (array_slice($links, 0, 15) as $lm) {
                    $link = $lm[1]; $inner = $lm[2]; $title = '';
                    if (preg_match('/alt="([^"]+)"/i', $inner, $m)) $title = clean($m[1]);
                    if (!$title && preg_match('/<(?:h[234]|span|strong)[^>]*>(.*?)<\//si', $inner, $m)) $title = clean($m[1]);
                    $img = ''; if (preg_match('/src="([^"]+)"/i', $inner, $m)) $img = $m[1];
                    $price = ''; if (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $inner, $m)) $price = trim($m[1]) . ' EUR';
                    if ($title && $link) {
                        $results[] = [
                            'store' => 'Piano Chollo', 'location' => 'Ontinyent, Espanya',
                            'title' => clean($title), 'year' => extractYear($title, $title),
                            'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                            'desc' => 'renovado segunda mano',
                        ];
                    }
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 44) KLAVIER (Murcia/Alicante) - Odoo
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['espanya', 'europa'])) {
    try {
        $body = fetch('https://www.klavier.es/shop/category/pianos-segunda-mano-455');
        if ($body) {
            if (preg_match_all('/<form[^>]+action="[^"]*\/shop\/cart\/update"[^>]*>(.*?)<\/form>/si', $body, $items)) {
                foreach (array_slice($items[1], 0, 20) as $item) {
                    $title = ''; $price = ''; $link = ''; $img = '';
                    if (preg_match('/<a[^>]+href="(https?:\/\/www\.klavier\.es\/shop\/[^"]+)"[^>]*>/i', $item, $m)) $link = $m[1];
                    if (preg_match('/<(?:h[2345]|span)[^>]*class="[^"]*product[^"]*name[^"]*"[^>]*>(.*?)<\//si', $item, $m)) $title = clean($m[1]);
                    elseif (preg_match('/itemprop="name"[^>]*>(.*?)<\//si', $item, $m)) $title = clean($m[1]);
                    if (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $item, $m)) $price = trim($m[1]) . ' EUR';
                    elseif (preg_match('/itemprop="price"[^>]*content="([\d.]+)"/i', $item, $m)) {
                        $val = (float)$m[1]; $price = $val > 0 ? number_format($val, 0, ',', '.') . ' EUR' : '';
                    }
                    if (preg_match('/<img[^>]+src="([^"]+)"/i', $item, $m)) $img = $m[1];
                    if ($title && $link) {
                        $results[] = [
                            'store' => 'Klavier', 'location' => 'Murcia, Espanya',
                            'title' => clean($title), 'year' => extractYear($title, $title),
                            'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                            'desc' => 'segunda mano ocasion',
                        ];
                    }
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 45) KLAVIER KREISEL (Germany) - Magento
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch('https://www.klavier-kreisel.de/klavier/gebraucht.html?product_list_limit=96');
        if ($body && preg_match_all('/<li[^>]*class="[^"]*product-item[^"]*"[^>]*>(.*?)<\/li>/si', $body, $items)) {
            foreach (array_slice($items[1], 0, 40) as $item) {
                $title = ''; $price = ''; $link = ''; $img = '';
                if (preg_match('/product-item-link[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/si', $item, $m)) {
                    $link = trim($m[1]); $title = clean($m[2]);
                }
                if (preg_match('/price-wrapper[^>]*>.*?([\d.,]+)/si', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                } elseif (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $item, $m)) {
                    $price = trim($m[1]) . ' EUR';
                }
                if (preg_match('/<img[^>]+src="([^"]+)"/i', $item, $m)) $img = $m[1];
                if ($title && $link) {
                    $results[] = [
                        'store' => 'Klavier Kreisel', 'location' => 'Alemanya',
                        'title' => clean($title), 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'gebraucht used segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 46) KLAVIERHALLE (Altenberge, Germany) - Custom/Static
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        for ($pg = 1; $pg <= 3; $pg++) {
            $pgStr = str_pad($pg, 3, '0', STR_PAD_LEFT);
            $body = fetch("https://www.klavierhalle.de/database/dblstk_p_{$pgStr}.html");
            if (!$body) break;
            if (preg_match_all('/<a[^>]+href="(\/klavier\/[^"]+\.html)"[^>]*>(.*?)<\/a>/si', $body, $links, PREG_SET_ORDER)) {
                foreach (array_slice($links, 0, 20) as $lm) {
                    $link = 'https://www.klavierhalle.de' . $lm[1];
                    $title = clean($lm[2]);
                    if (mb_strlen($title) < 3) continue;
                    $price = '';
                    $pos = strpos($body, $lm[0]);
                    if ($pos !== false) {
                        $ctx = substr($body, $pos, 500);
                        if (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $ctx, $m)) $price = trim($m[1]) . ' EUR';
                    }
                    $results[] = [
                        'store' => 'Klavierhalle', 'location' => 'Altenberge, Alemanya',
                        'title' => $title, 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => '',
                        'desc' => 'gebraucht used segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 47) BESBRODE PIANOS (Leeds, UK) - Custom/Static
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch('https://www.besbrodepianos.co.uk/listing.htm');
        if ($body) {
            if (preg_match_all('/<a[^>]+href="(\/piano-sale\/[^"]+\.htm)"[^>]*>(.*?)<\/a>/si', $body, $links, PREG_SET_ORDER)) {
                foreach (array_slice($links, 0, 20) as $lm) {
                    $link = 'https://www.besbrodepianos.co.uk' . $lm[1];
                    $title = clean($lm[2]);
                    if (mb_strlen($title) < 3 || str_contains(strtolower($title), 'click')) continue;
                    $price = '';
                    $pos = strpos($body, $lm[0]);
                    if ($pos !== false) {
                        $ctx = substr($body, max(0, $pos - 100), 600);
                        if (preg_match('/(?:£|GBP)\s*([\d,]+)/i', $ctx, $m)) $price = trim($m[1]) . ' GBP';
                    }
                    $results[] = [
                        'store' => 'Besbrode Pianos', 'location' => 'Leeds, UK',
                        'title' => $title, 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => '',
                        'desc' => 'used pre-owned segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 48) PIANOZ (Maidenhead, UK) - Drupal
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch('https://pianoz.com/pianos-for-sale');
        if ($body) {
            if (preg_match_all('/<a[^>]+href="(\/piano-sale\/piano\/[^"]+)"[^>]*>(.*?)<\/a>/si', $body, $links, PREG_SET_ORDER)) {
                $pzSeen = [];
                foreach (array_slice($links, 0, 30) as $lm) {
                    $link = 'https://pianoz.com' . $lm[1];
                    if (isset($pzSeen[$link])) continue;
                    $pzSeen[$link] = true;
                    $title = clean(strip_tags($lm[2]));
                    if (mb_strlen($title) < 3) continue;
                    $img = '';
                    if (preg_match('/src="([^"]+)"/i', $lm[2], $m)) $img = $m[1];
                    $price = '';
                    $pos = strpos($body, $lm[0]);
                    if ($pos !== false) {
                        $ctx = substr($body, $pos, 500);
                        if (preg_match('/(?:£|GBP)\s*([\d,]+)/i', $ctx, $m)) $price = trim($m[1]) . ' GBP';
                    }
                    $results[] = [
                        'store' => 'PIANOZ', 'location' => 'UK',
                        'title' => $title, 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'used pre-owned segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 49) PIANOSHOP.FR (France) - Custom
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch('https://www.pianoshop.fr/occasions');
        if ($body) {
            if (preg_match_all('/<a[^>]+href="(\/[^"]*-id\d+[^"]*)"[^>]*>(.*?)<\/a>/si', $body, $links, PREG_SET_ORDER)) {
                $psSeen = [];
                foreach (array_slice($links, 0, 20) as $lm) {
                    $link = 'https://www.pianoshop.fr' . $lm[1];
                    if (isset($psSeen[$link])) continue;
                    $psSeen[$link] = true;
                    $inner = $lm[2]; $title = '';
                    if (preg_match('/alt="([^"]+)"/i', $inner, $m)) $title = clean($m[1]);
                    if (!$title) $title = clean(strip_tags($inner));
                    if (mb_strlen($title) < 3) continue;
                    $img = '';
                    if (preg_match('/src="([^"]+)"/i', $inner, $m)) {
                        $img = $m[1];
                        if (!str_starts_with($img, 'http')) $img = 'https://www.pianoshop.fr' . $img;
                    }
                    $price = '';
                    $pos = strpos($body, $lm[0]);
                    if ($pos !== false) {
                        $ctx = substr($body, $pos, 500);
                        if (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $ctx, $m)) $price = trim($m[1]) . ' EUR';
                    }
                    $results[] = [
                        'store' => 'Pianoshop.fr', 'location' => 'Franca',
                        'title' => $title, 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'occasion used segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 50) QUATRE MAINS PIANOS (Ghent, Belgium) - Squarespace
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch('https://www.quatremainspianos.be/tweedehands-pianos');
        if ($body) {
            if (preg_match_all('/<a[^>]+href="(\/tweedehands-pianos\/p\/[^"]+)"[^>]*>(.*?)<\/a>/si', $body, $links, PREG_SET_ORDER)) {
                $qmSeen = [];
                foreach (array_slice($links, 0, 20) as $lm) {
                    $link = 'https://www.quatremainspianos.be' . $lm[1];
                    if (isset($qmSeen[$link])) continue;
                    $qmSeen[$link] = true;
                    $title = clean(strip_tags($lm[2]));
                    if (mb_strlen($title) < 3) continue;
                    $img = '';
                    if (preg_match('/src="([^"]+squarespace[^"]+)"/i', $lm[2], $m)) $img = $m[1];
                    $price = '';
                    $pos = strpos($body, $lm[0]);
                    if ($pos !== false) {
                        $ctx = substr($body, $pos, 500);
                        if (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $ctx, $m)) $price = trim($m[1]) . ' EUR';
                    }
                    $results[] = [
                        'store' => 'Quatre Mains', 'location' => 'Ghent, Belgica',
                        'title' => $title, 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'tweedehands used segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 51) KLAVIERLOFT (Vienna, Austria) - Weebly
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch('https://www.klavierloft.at/gebrauchte-pianos.html');
        if ($body) {
            if (preg_match_all('/<a[^>]+href="(https?:\/\/www\.klavierloft\.at\/[^"]+\.html)"[^>]*>(.*?)<\/a>/si', $body, $links, PREG_SET_ORDER)) {
                $klSeen = [];
                foreach (array_slice($links, 0, 20) as $lm) {
                    $link = $lm[1];
                    if ($link === 'https://www.klavierloft.at/gebrauchte-pianos.html') continue;
                    if (isset($klSeen[$link])) continue;
                    $klSeen[$link] = true;
                    $title = clean(strip_tags($lm[2]));
                    if (mb_strlen($title) < 3 || str_contains(strtolower($title), 'kontakt') || str_contains(strtolower($title), 'impressum')) continue;
                    $img = '';
                    if (preg_match('/src="([^"]+)"/i', $lm[2], $m)) $img = $m[1];
                    $results[] = [
                        'store' => 'KlavierLoft', 'location' => 'Viena, Austria',
                        'title' => $title, 'year' => extractYear($title, $title),
                        'price' => '-', 'link' => $link, 'image' => $img,
                        'desc' => 'gebraucht used segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 52) SCORTICATI PIANOFORTI (Milan, Italy) - Weebly
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch('https://www.scorticatipianoforti.it/pianoforti-usati-milano');
        if ($body) {
            if (preg_match_all('/<a[^>]+href="(https?:\/\/www\.scorticatipianoforti\.it\/catalogo\/[^"]+)"[^>]*>(.*?)<\/a>/si', $body, $links, PREG_SET_ORDER)) {
                $scSeen = [];
                foreach (array_slice($links, 0, 20) as $lm) {
                    $link = $lm[1];
                    if (isset($scSeen[$link])) continue;
                    $scSeen[$link] = true;
                    $title = '';
                    if (preg_match('/alt="([^"]+)"/i', $lm[2], $m)) $title = clean($m[1]);
                    if (!$title) $title = clean(strip_tags($lm[2]));
                    if (mb_strlen($title) < 3) continue;
                    $img = '';
                    if (preg_match('/src="([^"]+)"/i', $lm[2], $m)) $img = $m[1];
                    $price = '';
                    $pos = strpos($body, $lm[0]);
                    if ($pos !== false) {
                        $ctx = substr($body, $pos, 500);
                        if (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $ctx, $m)) $price = trim($m[1]) . ' EUR';
                    }
                    $results[] = [
                        'store' => 'Scorticati', 'location' => 'Mila, Italia',
                        'title' => $title, 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'usato used segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 53) BONTEMPI PIANOFORTI (Roma, Italy) - Custom
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $body = fetch('https://www.pianofortibontempiroma.com/pianoforti-usato-garantito');
        if ($body) {
            if (preg_match_all('/<a[^>]+href="(https?:\/\/www\.pianofortibontempiroma\.com\/[^"]*pianofort[^"]*)"[^>]*>(.*?)<\/a>/si', $body, $links, PREG_SET_ORDER)) {
                $btSeen = [];
                foreach (array_slice($links, 0, 20) as $lm) {
                    $link = $lm[1];
                    if ($link === 'https://www.pianofortibontempiroma.com/pianoforti-usato-garantito') continue;
                    if (isset($btSeen[$link])) continue;
                    $btSeen[$link] = true;
                    $title = '';
                    if (preg_match('/alt="([^"]+)"/i', $lm[2], $m)) $title = clean($m[1]);
                    if (!$title) $title = clean(strip_tags($lm[2]));
                    if (mb_strlen($title) < 3) continue;
                    $img = '';
                    if (preg_match('/src="([^"]+)"/i', $lm[2], $m)) $img = $m[1];
                    $price = '';
                    $pos = strpos($body, $lm[0]);
                    if ($pos !== false) {
                        $ctx = substr($body, $pos, 500);
                        if (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $ctx, $m)) $price = trim($m[1]) . ' EUR';
                    }
                    $results[] = [
                        'store' => 'Bontempi', 'location' => 'Roma, Italia',
                        'title' => $title, 'year' => extractYear($title, $title),
                        'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                        'desc' => 'usato garantito used segunda mano',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ══════════════════════════════════════════════════════════════
// 54) KLAVIANO (Europe-wide aggregator) - Custom
// ══════════════════════════════════════════════════════════════
if (in_array($region, ['europa'])) {
    try {
        $q = urlencode($searchModel);
        $body = fetch("https://www.klaviano.com/pianos-for-sale/yamaha.html?search={$q}&condition=used");
        if ($body) {
            if (preg_match_all('/<div[^>]*class="[^"]*piano-item[^"]*"[^>]*>(.*?)<\/div>\s*<\/div>/si', $body, $items)) {
                foreach (array_slice($items[1], 0, 25) as $item) {
                    $title = ''; $price = ''; $link = ''; $img = '';
                    if (preg_match('/href="([^"]+\.html)"/i', $item, $m)) {
                        $link = $m[1];
                        if (!str_starts_with($link, 'http')) $link = 'https://www.klaviano.com' . $link;
                    }
                    if (preg_match('/<h[234][^>]*>(.*?)<\/h[234]>/si', $item, $m)) $title = clean($m[1]);
                    elseif (preg_match('/alt="([^"]+)"/i', $item, $m)) $title = clean($m[1]);
                    if (preg_match('/([\d.,]+)\s*(?:€|EUR)/i', $item, $m)) $price = trim($m[1]) . ' EUR';
                    if (preg_match('/<img[^>]+(?:data-src|src)="([^"]+)"/i', $item, $m)) $img = $m[1];
                    if ($title && $link) {
                        $results[] = [
                            'store' => 'Klaviano', 'location' => 'Europa',
                            'title' => $title, 'year' => extractYear($title, $title),
                            'price' => $price ?: '-', 'link' => $link, 'image' => $img,
                            'desc' => 'used occasion gebraucht segunda mano',
                        ];
                    }
                }
            }
            // Fallback: link-based extraction
            if (empty(array_filter($results, fn($r) => $r['store'] === 'Klaviano'))) {
                if (preg_match_all('/<a[^>]+href="(\/pianos-for-sale\/yamaha\/[^"]+\.html)"[^>]*>(.*?)<\/a>/si', $body, $links, PREG_SET_ORDER)) {
                    $kvSeen = [];
                    foreach (array_slice($links, 0, 20) as $lm) {
                        $link = 'https://www.klaviano.com' . $lm[1];
                        if (isset($kvSeen[$link])) continue;
                        $kvSeen[$link] = true;
                        $title = clean(strip_tags($lm[2]));
                        if (mb_strlen($title) < 3) continue;
                        $results[] = [
                            'store' => 'Klaviano', 'location' => 'Europa',
                            'title' => $title, 'year' => extractYear($title, $title),
                            'price' => '-', 'link' => $link, 'image' => '',
                            'desc' => 'used occasion gebraucht segunda mano',
                        ];
                    }
                }
            }
        }
    } catch (\Throwable $e) {}
}

// ── Classificació nou/2a mà ─────────────────────────────────
foreach ($results as &$r) {
    $r['condition'] = classifyCondition($r['title'], $r['link'], $r['store'], $r['desc'] ?? '');
}
unset($r);

// Filtrar: només segona mà confirmada
$results = array_values(array_filter($results, fn($r) => $r['condition'] === '2a_ma'));

// ── Filtratge de rellevància ────────────────────────────────
// Extract the model code (non-yamaha part) - this MUST match
$modelClean = mb_strtolower(preg_replace('/\byamaha\b/i', '', $model));
$modelCode = trim(preg_replace('/[\s\-]+/', '', $modelClean));

$modelPattern = '/(?<![a-z0-9])' . preg_quote($modelCode, '/') . '(?![0-9])/i';
$results = array_values(array_filter($results, function($r) use ($modelPattern) {
    if (empty($r['title']) || empty($r['link'])) return false;
    $text = mb_strtolower($r['title'] . ' ' . ($r['desc'] ?? ''));
    return (bool) preg_match($modelPattern, $text);
}));

// ── Deduplicació per link ───────────────────────────────────
$seen = [];
$results = array_values(array_filter($results, function($r) use (&$seen) {
    $key = $r['link'];
    if (isset($seen[$key])) return false;
    $seen[$key] = true;
    return true;
}));

// ── Resposta ────────────────────────────────────────────────
$response = [
    'model'   => $model,
    'region'  => $region,
    'count'   => count($results),
    'results' => array_values($results),
    'sources' => array_values(array_unique(array_column($results, 'store'))),
    'cached'  => false,
];

$json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
@file_put_contents($cacheFile, $json);
echo $json;
