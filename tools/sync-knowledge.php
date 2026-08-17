<?php
/*
 * MTPC Knowledge Sync — PHP 5.6 compatible.
 * Run by cPanel Cron. It only reads public pages from mtpc.edu.vn and writes
 * normalized chunks plus a lightweight inverted index outside public_html.
 */

date_default_timezone_set('Asia/Ho_Chi_Minh');

$baseUrl = 'https://mtpc.edu.vn';
$privateDir = '/home/mtpc/private/mtpc-knowledge';
$maxUrls = 160;
$force = in_array('--full', isset($argv) ? $argv : array(), true);

function mtpc_json_read($path, $fallback) {
    if (!is_file($path)) return $fallback;
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : $fallback;
}

function mtpc_json_write($path, $data) {
    $temp = $path . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($temp, $json, LOCK_EX) === false) {
        throw new Exception('Không thể ghi file: ' . basename($path));
    }
    if (!rename($temp, $path)) {
        @unlink($temp);
        throw new Exception('Không thể hoàn tất file: ' . basename($path));
    }
}

function mtpc_fetch($url) {
    $curl = curl_init($url);
    curl_setopt_array($curl, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'MTPC-KnowledgeSync/1.0 (+https://agent.mtpc.edu.vn)',
        CURLOPT_HTTPHEADER => array('Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.5'),
    ));
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    $finalUrl = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
    curl_close($curl);
    if ($body === false || $status < 200 || $status >= 300) return null;
    return array('body' => $body, 'type' => $type, 'url' => $finalUrl ? $finalUrl : $url);
}

function mtpc_allowed_url($url, $baseHost) {
    $parts = @parse_url($url);
    if (!$parts || empty($parts['host'])) return false;
    $host = strtolower($parts['host']);
    return $host === $baseHost || $host === 'www.' . $baseHost;
}

function mtpc_sitemap_urls($sitemapUrl, $baseHost, $depth) {
    if ($depth > 2) return array();
    $fetched = mtpc_fetch($sitemapUrl);
    if (!$fetched) return array();
    libxml_use_internal_errors(true);
    $xml = @simplexml_load_string($fetched['body']);
    if (!$xml) return array();
    $urls = array();
    foreach ($xml->url as $entry) {
        $loc = trim((string) $entry->loc);
        if ($loc && mtpc_allowed_url($loc, $baseHost)) $urls[] = $loc;
    }
    foreach ($xml->sitemap as $entry) {
        $loc = trim((string) $entry->loc);
        if ($loc && mtpc_allowed_url($loc, $baseHost)) {
            $urls = array_merge($urls, mtpc_sitemap_urls($loc, $baseHost, $depth + 1));
        }
    }
    return $urls;
}

function mtpc_extract_page($html, $url) {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    if (!$loaded) return null;
    $xpath = new DOMXPath($doc);
    $titleNodes = $xpath->query('//title');
    $title = $titleNodes && $titleNodes->length ? trim($titleNodes->item(0)->textContent) : '';
    $remove = $xpath->query('//script|//style|//noscript|//svg|//header|//footer|//nav|//form|//iframe');
    if ($remove) {
        for ($i = $remove->length - 1; $i >= 0; $i--) {
            $node = $remove->item($i);
            if ($node && $node->parentNode) $node->parentNode->removeChild($node);
        }
    }
    $bodyNodes = $xpath->query('//main|//article|//body');
    $text = '';
    if ($bodyNodes && $bodyNodes->length) $text = $bodyNodes->item(0)->textContent;
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', trim($text));
    if (mtpc_text_length($text) < 120) return null;
    if ($title === '') $title = 'Thông tin MTPC';
    return array('url' => $url, 'title' => $title, 'text' => $text);
}

function mtpc_text_length($text) {
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

function mtpc_text_slice($text, $start, $length) {
    return function_exists('mb_substr') ? mb_substr($text, $start, $length, 'UTF-8') : substr($text, $start, $length);
}

function mtpc_normalize($text) {
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($converted !== false) $text = $converted;
    $text = preg_replace('/[^a-z0-9]+/i', ' ', $text);
    return trim($text);
}

function mtpc_terms($text) {
    $stop = array('va','la','cua','cho','voi','nhung','cac','mot','nhung','duoc','tai','the','ve','trong','khi','tu','den','nay','co','khong','toi','ban','em','anh','chi','hoc','truong','thong','tin');
    $parts = preg_split('/\s+/', mtpc_normalize($text));
    $terms = array();
    foreach ($parts as $term) {
        if (strlen($term) < 2 || in_array($term, $stop, true)) continue;
        $terms[$term] = true;
    }
    return array_keys($terms);
}

function mtpc_chunks_for_page($page) {
    $size = 900;
    $overlap = 140;
    $text = $page['text'];
    $length = mtpc_text_length($text);
    $chunks = array();
    $offset = 0;
    $number = 0;
    while ($offset < $length) {
        $part = trim(mtpc_text_slice($text, $offset, $size));
        if (mtpc_text_length($part) >= 100) {
            $chunks[] = array(
                'id' => sha1($page['url'] . '#' . $number . '#' . $part),
                'url' => $page['url'],
                'title' => $page['title'],
                'text' => $part,
                'updated_at' => date('c'),
            );
            $number++;
        }
        if ($offset + $size >= $length) break;
        $offset += $size - $overlap;
    }
    return $chunks;
}

try {
    if (!is_dir($privateDir) && !mkdir($privateDir, 0700, true)) throw new Exception('Không tạo được thư mục kho tri thức.');
    $baseHost = parse_url($baseUrl, PHP_URL_HOST);
    $urls = mtpc_sitemap_urls($baseUrl . '/sitemap.xml', $baseHost, 0);
    if (!$urls) {
        $urls = array($baseUrl . '/', $baseUrl . '/gioi-thieu', $baseUrl . '/tuyen-sinh', $baseUrl . '/nganh-dao-tao', $baseUrl . '/tin-tuc', $baseUrl . '/sinh-vien', $baseUrl . '/lien-he');
    }
    $urls = array_values(array_unique(array_slice($urls, 0, $maxUrls)));
    $oldPages = mtpc_json_read($privateDir . '/pages.json', array());
    $pages = array();
    $changed = 0;
    foreach ($urls as $url) {
        $fetched = mtpc_fetch($url);
        if (!$fetched || stripos($fetched['type'], 'html') === false) continue;
        $page = mtpc_extract_page($fetched['body'], $fetched['url']);
        if (!$page) continue;
        $hash = sha1($page['title'] . "\n" . $page['text']);
        if (!$force && isset($oldPages[$page['url']]['hash']) && $oldPages[$page['url']]['hash'] === $hash) {
            $pages[$page['url']] = $oldPages[$page['url']];
        } else {
            $page['hash'] = $hash;
            $page['synced_at'] = date('c');
            $pages[$page['url']] = $page;
            $changed++;
        }
    }
    $chunks = array();
    $index = array();
    foreach ($pages as $page) {
        foreach (mtpc_chunks_for_page($page) as $chunk) {
            $chunks[] = $chunk;
            $terms = mtpc_terms($chunk['title'] . ' ' . $chunk['text']);
            foreach ($terms as $term) {
                if (!isset($index[$term])) $index[$term] = array();
                $index[$term][] = $chunk['id'];
            }
        }
    }
    mtpc_json_write($privateDir . '/pages.json', $pages);
    mtpc_json_write($privateDir . '/chunks.json', array('version' => 1, 'built_at' => date('c'), 'chunks' => $chunks));
    mtpc_json_write($privateDir . '/index.json', array('version' => 1, 'built_at' => date('c'), 'terms' => $index));
    mtpc_json_write($privateDir . '/status.json', array('built_at' => date('c'), 'source_count' => count($pages), 'chunk_count' => count($chunks), 'changed_count' => $changed));
    echo 'OK: ' . count($pages) . ' pages, ' . count($chunks) . ' chunks, ' . $changed . ' changed' . PHP_EOL;
} catch (Exception $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
