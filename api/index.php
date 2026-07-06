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

function extractYear(string $text): string {
    if (preg_match('/\b(19[6-9]\d|20[0-2]\d)(?=[^0-9]|$)/', $text, $m)) return $m[1];
    return '';
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
    $usedStores = ['Art Guinardo','Pianos Low Cost','La Casa dels Pianos','Pianos Can Puig','Sinergia Music','Jorquera Pianos'];
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
                        $results[] = [
                            'store'    => 'La Casa dels Pianos',
                            'location' => 'Barcelona, Catalunya',
                            'title'    => $title,
                            'year'     => extractYear($title . ' ' . $desc),
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
                        $results[] = [
                            'store'    => 'Art Guinardo',
                            'location' => 'Barcelona, Catalunya',
                            'title'    => $title,
                            'year'     => extractYear($title),
                            'price'    => $price ?: '-',
                            'link'     => $link,
                            'image'    => $img,
                            'desc'     => 'segunda mano',
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
                        $results[] = [
                            'store'    => 'Audenis',
                            'location' => 'Barcelona, Catalunya',
                            'title'    => $title,
                            'year'     => extractYear($title),
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
                        $results[] = [
                            'store'    => 'Pianos Low Cost',
                            'location' => 'Madrid, Espanya',
                            'title'    => $title,
                            'year'     => extractYear($title),
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
                            'year'     => extractYear($title),
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
                        'year'     => extractYear($title . ' ' . $desc),
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
                            'year'     => extractYear($title),
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
                    $year = extractYear($chunk);
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
// 9) KLEINANZEIGEN.DE (Alemanya) - JSON-LD
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
                        'year'     => extractYear($title . ' ' . $desc),
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
// 10) MARKTPLAATS.NL (Holanda) - __NEXT_DATA__
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
                        'year'     => extractYear($title . ' ' . $desc),
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
// 11) EBAY (.es i .de)
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
                        'year'     => extractYear($title),
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
// 12) WALLAPOP (Espanya) - API JSON
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
                        'year'     => extractYear($title . ' ' . $desc),
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
// 13) LEBONCOIN (França) - __NEXT_DATA__
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
                        'year'     => extractYear($title . ' ' . ($ad['body'] ?? '')),
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
