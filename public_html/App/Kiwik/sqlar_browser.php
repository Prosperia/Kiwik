<?php
// sqlar-browse.php
// SQLar browser with: search (filename + contents), Dokuwiki rendering, syntax highlighting,
// Dokuwiki code blocks (<code ...>...</code> and indented pre), and inline <html>...</html> passthrough.

// ---------- Config ----------
$DEFAULT_DB = __DIR__ . '/db/Dokuwiki-Inspiron.db'; // Change to your archive. You can also pass ?db=/path/to/archive.db
$ALLOW_DB_QUERY_PARAM = true;              // allow ?db=... (restrict in production)
$ALLOWED_DB_DIRS = [__DIR__];             // allowed directories for db if query param allowed

// ---------- Helpers ----------
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function join_path(string $base, string $child): string { return ltrim(rtrim($base, '/').'/'.ltrim($child, '/'), '/'); }
function parent_path(string $path): string { $path = trim($path, '/'); if ($path==='') return ''; $p = explode('/', $path); array_pop($p); return implode('/', $p); }

function resolve_db_path(string $requested, array $allowed_dirs): string {
    $requested = trim($requested);
    if ($requested === '') return '';
    $real = realpath($requested);
    if ($real === false) return '';
    foreach ($allowed_dirs as $dir) {
        $root = rtrim(realpath($dir) ?: $dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (stripos($real, $root) === 0) return $real;
    }
    return '';
}

function open_sqlite_ro(string $path): PDO {
    if (!is_file($path)) { http_response_code(404); exit('Archive not found: '.h($path)); }
    $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

// Extract content from sqlar row (handles zlib compression and symlink/empty)
function extract_content_from_row(array $row): string {
    // symlink: sz==-1; data contains target text
    $sz = (int)($row['sz'] ?? 0);
    $blob = $row['data'] ?? '';
    if ($sz === -1) return (string)$blob;
    if ($sz === 0) return (string)$blob; // empty file or explicit empty data
    // If stored length equals original size -> plaintext
    if (strlen($blob) === $sz) return $blob;
    // else zlib compressed (SQLAR stores zlib format)
    $out = @gzuncompress($blob);
    if ($out === false) {
        // try raw inflate as a fallback
        $out = @gzinflate($blob);
        if ($out === false) throw new RuntimeException('Failed to decompress data');
    }
    return $out;
}

// -----------------------
// Dokuwiki lightweight parser
// - Supports headings, bold/italic/underline/monospace
// - Links [[url|title]]
// - Unordered lists (lines starting with '  *' or '  -')
// - Tables (^ hdr ^) and (| cell |)
// - Code blocks: <code [lang]>...</code> and indented blocks (lines starting with a space)
// - Inline HTML blocks: <html>...</html> are passed through unescaped
// Note: This is a pragmatic lightweight renderer, not a full Dokuwiki implementation.
// -----------------------
function dokuwiki_to_html(string $text): string {
    // Preserve original line endings
    $text = str_replace("\r\n", "\n", $text);

    $htmlBlocks = [];
    $codeBlocks = [];

    // 1) Extract <html>...</html> blocks (allow raw HTML passthrough)
    $text = preg_replace_callback('#<html>(.*?)</html>#is', function($m) use (&$htmlBlocks) {
        $k = '%%HTMLBLOCK'.count($htmlBlocks).'%%';
        $htmlBlocks[$k] = $m[1]; // raw inner HTML (keep as-is)
        return $k;
    }, $text);

    // 2) Extract <code ...>...</code> blocks (store with optional language)
    $text = preg_replace_callback('#<code(?:\s+(\w+))?>(.*?)</code>#is', function($m) use (&$codeBlocks) {
        $lang = isset($m[1]) && $m[1] !== '' ? strtolower($m[1]) : '';
        $k = '%%CODEBLOCK'.count($codeBlocks).'%%';
        $codeBlocks[$k] = ['lang'=>$lang, 'code'=>$m[2]];
        return $k;
    }, $text);

    // 3) Extract indented pre blocks (contiguous lines that start with a space)
    $text = preg_replace_callback('/(?:^|\n)((?: (?:.*)\n?)+)/', function($m) use (&$codeBlocks) {
        $k = '%%CODEBLOCK'.count($codeBlocks).'%%';
        // strip one leading space from each line (preserve internal indentation)
        $block = preg_replace('/^ /m', '', $m[1]);
        $codeBlocks[$k] = ['lang'=>'', 'code'=>$block];
        return "\n".$k."\n";
    }, $text);

    // 4) Escape the rest to prevent XSS
    $text = h($text);

    // 5) Headings (from biggest to smallest)
    $text = preg_replace('/^======\s*(.*?)\s*======$/m', '<h1>$1</h1>', $text);
    $text = preg_replace('/^=====\s*(.*?)\s*=====$/m',   '<h2>$1</h2>', $text);
    $text = preg_replace('/^====\s*(.*?)\s*====$/m',     '<h3>$1</h3>', $text);
    $text = preg_replace('/^===\s*(.*?)\s*===$/m',       '<h4>$1</h4>', $text);
    $text = preg_replace('/^==\s*(.*?)\s*==$/m',         '<h5>$1</h5>', $text);

    // 6) Inline formatting
    $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text); // **bold**
    $text = preg_replace('/\/\/(.*?)\/\//s', '<em>$1</em>', $text);             // //italic//
    $text = preg_replace('/__(.*?)__/s', '<u>$1</u>', $text);                     // __underline__
    $text = preg_replace("/''(.*?)''/s", '<code>$1</code>', $text);             // ''monospace''

    // 7) Links [[url|title]] or [[title|url]] (keep URL raw)
    $text = preg_replace_callback('/\[\[(.*?)\]\]/', function($m) {
        $parts = explode('|', $m[1], 2);
        if (count($parts) === 2) {
            $a = $parts[0]; $b = $parts[1];
            // Guess which is URL (has :// or starts with /)
            if (preg_match('#^https?://#i', $a) || strpos($a, '/') === 0) {
                $url = $a; $title = $b;
            } elseif (preg_match('#^https?://#i', $b) || strpos($b, '/') === 0) {
                $url = $b; $title = $a;
            } else { $url = $a; $title = $b; }
        } else {
            $url = $parts[0]; $title = $parts[0];
        }
        // url kept as-is (not escaped because we escaped earlier with h())
        return '<a href="'.h($url).'">'.h($title).'</a>';
    }, $text);

    // 8) Tables: ^ hdr ^  and | cell |
    // Normalize cells (strip surrounding spaces inside cells)
    $text = preg_replace_callback('/^\^(.*)\^$/m', function($m){
        $cells = array_map('trim', explode('^', trim($m[1])));
        $t = '<tr>';
        foreach ($cells as $c) if ($c !== '') $t .= '<th>'.$c.'</th>';
        $t .= '</tr>';
        return $t;
    }, $text);
    $text = preg_replace_callback('/^\|(.*)\|$/m', function($m){
        $cells = array_map('trim', explode('|', trim($m[1])));
        $t = '<tr>';
        foreach ($cells as $c) if ($c !== '') $t .= '<td>'.$c.'</td>';
        $t .= '</tr>';
        return $t;
    }, $text);
    // wrap consecutive <tr>..</tr> groups with <table>
    $text = preg_replace('/((?:<tr>.*?<\/tr>\s*)+)/s', '<table>$1</table>', $text);

    // 9) Unordered lists: lines starting with '*' or '-'
    // Convert "* item" and nested by leading spaces
    $lines = explode("\n", $text);
    $outLines = [];
    $listStack = [];
    foreach ($lines as $line) {
        if (preg_match('/^(\s*)([\*\-])\s+(.*)$/', $line, $m)) {
            $indent = strlen($m[1]);
            $item = $m[3];
            // Determine current level
            $level = intval($indent / 2);
            while (count($listStack) > $level) { $outLines[] = '</ul>'; array_pop($listStack); }
            while (count($listStack) < $level) { $outLines[] = '<ul>'; $listStack[] = true; }
            if (empty($listStack)) { $outLines[] = '<ul>'; $listStack[] = true; }
            $outLines[] = '<li>'.$item.'</li>';
        } else {
            while (!empty($listStack)) { $outLines[] = '</ul>'; array_pop($listStack); }
            $outLines[] = $line;
        }
    }
    while (!empty($listStack)) { $outLines[] = '</ul>'; array_pop($listStack); }
    $text = implode("\n", $outLines);

    // 10) Paragraphs: wrap lines that look like plain text into <p> if not already block
    $text = preg_replace('/(^|\n)(?!\s*(<h|<ul|<table|<pre|<code|<blockquote|<div|<p|<img|<a))([^\n][^\n]*)(?=\n|$)/i', "$1<p>$3</p>", $text);

    // 11) Restore code blocks placeholders
    if (!empty($codeBlocks)) {
        foreach ($codeBlocks as $k => $block) {
            $code = $block['code'];
            $lang = $block['lang'];
            // escape code content now (it was escaped earlier) but we want literal within <code>
            $code_esc = h($code);
            $cls = $lang ? ' class="language-'.h($lang).'"' : '';
            $replacement = '<pre><code'.$cls.'>'. $code_esc .'</code></pre>';
            $text = str_replace($k, $replacement, $text);
        }
    }

    // 12) Restore HTML blocks (unescaped raw HTML)
    if (!empty($htmlBlocks)) {
        foreach ($htmlBlocks as $k => $rawHtml) {
            // rawHtml is taken from original source; insert as-is
            $text = str_replace($k, $rawHtml, $text);
        }
    }

    // 13) Return final HTML (trim and normalize newlines)
    return trim($text);
}

// -----------------------
// Request handling / UI
// -----------------------
$path = isset($_GET['path']) ? trim((string)$_GET['path'], '/') : '';
action: // label for clarity
$action = $_GET['action'] ?? 'browse'; // browse | view | download | raw
$search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$dbPath = $DEFAULT_DB;
if ($ALLOW_DB_QUERY_PARAM && isset($_GET['db'])) {
    $candidate = resolve_db_path((string)$_GET['db'], $ALLOWED_DB_DIRS);
    if ($candidate !== '') $dbPath = $candidate;
}

$pdo = open_sqlite_ro($dbPath);

// Serve file download or raw view (no Dokuwiki rendering)
if (($action === 'download' || $action === 'raw') && isset($_GET['name'])) {
    $name = (string)$_GET['name'];
    $stmt = $pdo->prepare('SELECT name, mode, mtime, sz, data FROM sqlar WHERE name = :n');
    $stmt->execute([':n'=>$name]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); exit('Not found'); }
    if ((int)$row['sz'] === 0 && $row['data'] === null) { http_response_code(400); exit('Cannot download a directory'); }
    try { $content = extract_content_from_row($row); } catch (RuntimeException $e) { http_response_code(500); exit(h($e->getMessage())); }

    if ($action === 'raw') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>'.h($row['name']).'</title>';
        echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">';
//        echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/default.min.css">';
        echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>';
        echo '<script>hljs.highlightAll();</script>';
//        echo '</head><body style="font-family:monospace; white-space:pre-wrap; background:#111; color:#eee; padding:12px;">';
        echo '</head><body style="background:#0f172a;color:#e5e7eb;font-family:monospace;">';
        // highlight search term in raw/plain previews only
        if ($search !== '') {
            $pattern = '/' . preg_quote($search, '/') . '/i';
            $content = preg_replace_callback($pattern, function($m){ return '<mark style="background:#facc15;color:#000">'.h($m[0]).'</mark>'; }, h($content));
//            echo $content;
            echo '<pre><code>'. $content .'</code></pre>';
        } else {
            echo '<pre><code>'.h($content).'</code></pre>';
        }
        echo '</body></html>';
        exit;
    }

    // download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.basename($row['name']).'"');
    echo $content;
    exit;
}

// Browse / search listing
// If searching contents, iterate all rows and test filename and contents
$dirs = [];
$files = [];
if ($search !== '') {
    // stream through all rows to avoid loading everything into memory
    $stmt = $pdo->query('SELECT name, mode, mtime, sz, data FROM sqlar');
    while ($row = $stmt->fetch()) {
        $name = $row['name'];
        $matched = stripos($name, $search) !== false;
        if (!$matched && (int)$row['sz'] > 0) {
            try {
                $content = extract_content_from_row($row);
                if (stripos($content, $search) !== false) $matched = true;
            } catch (Throwable $e) { /* ignore decompress errors for search */ }
        }
        if ($matched) $files[$name] = $row;
    }
} else {
    // list immediate children under $path
    $stmt = $pdo->prepare(($path === '') ? 'SELECT name, mode, mtime, sz, data FROM sqlar' : 'SELECT name, mode, mtime, sz, data FROM sqlar WHERE name = :prefix OR name LIKE :like');
    if ($path === '') {
        $stmt->execute();
    } else {
        $stmt->execute([':prefix'=>$path, ':like'=>$path.'/%']);
    }
    while ($row = $stmt->fetch()) {
        $rowName = $row['name'];
        if ($rowName === $path) continue;
        $rel = ($path === '') ? $rowName : ltrim(substr($rowName, strlen($path)), '/');
        if ($rel === '') continue;
        $parts = explode('/', $rel, 2);
        $first = $parts[0];
        if (count($parts) === 1) {
            $files[$first] = $row;
        } else {
            $dirFull = join_path($path, $first);
            if (!isset($dirs[$first])) {
                $drow = $pdo->prepare('SELECT name, mode, mtime, sz, data FROM sqlar WHERE name = :n');
                $drow->execute([':n'=>$dirFull]);
                $dirs[$first] = $drow->fetch() ?: ['name'=>$dirFull,'mode'=>null,'mtime'=>null,'sz'=>0,'data'=>null];
            }
        }
    }
}

uksort($dirs, 'strnatcasecmp');
uksort($files, 'strnatcasecmp');

function format_mode($m){ if ($m===null) return ''; $m=(int)$m & 0777; $s=''; $perms=[0400=>'r',0200=>'w',0100=>'x',0040=>'r',0020=>'w',0010=>'x',0004=>'r',0002=>'w',0001=>'x']; foreach($perms as $b=>$c){$s.=($m&$b)?$c:'-';} return $s; }
function format_mtime($t){ return $t?date('Y-m-d H:i:s',(int)$t):''; }
function format_size($sz,$data){ if((int)$sz===-1) return 'symlink'; if((int)$sz===0 && $data===null) return 'dir'; if((int)$sz===0) return '0 B'; $n=(int)$sz;$u=['B','KB','MB','GB'];$i=0;while($n>=1024&&$i<count($u)-1){$n/=1024;$i++;}return ($n>=10?number_format($n,0):number_format($n,1)).' '.$u[$i]; }

// Breadcrumbs
$crumbs=[['','']]; if($path!==''){ $acc=''; foreach(explode('/',$path) as $seg){ $acc = join_path($acc,$seg); $crumbs[] = [$acc,$seg]; } }

// ----------- HTML UI -----------
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SQLar Browser</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
<script>hljs.highlightAll();</script>
<style>
:root{--bg:#0f172a;--fg:#e5e7eb;--muted:#94a3b8;--card:#0b1220}
body{background:var(--bg);color:var(--fg);font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:0}
.header{padding:12px 18px;background:#071123}
.container{max-width:1100px;margin:18px auto;padding:0 12px}
.panel{background:var(--card);padding:14px;border-radius:10px}
table{width:100%;border-collapse:collapse}
th,td{padding:8px;border-bottom:1px solid rgba(255,255,255,0.04)}
.muted{color:var(--muted)}
.btn{background:#1d4ed8;color:white;padding:6px 10px;border-radius:8px;text-decoration:none}
.dokuwiki-content{background:#fff;color:#111;padding:18px;border-radius:8px}
.dokuwiki-content h1,h2,h3,h4,h5{border-bottom:1px solid #eee;padding-bottom:6px}
.dokuwiki-content p{margin:8px 0}
//.dokuwiki-content code{background:#f4f4f4;padding:2px 6px;border-radius:4px}
.dokuwiki-content code{padding:2px 6px;border-radius:4px}
.dokuwiki-content pre{background:#0b1220;color:#e6eef8;padding:12px;border-radius:6px;overflow:auto}
.dokuwiki-content a{color:#0366d6}
mark{background:#facc15;color:#000}
</style>
</head>

<body>
<div class="header">
    <div class="container"><strong>SQLar Browser</strong> <br /><br />
        <div class="muted"> Archive: <code><?=h($dbPath)?></code></div>
    </div>
</div>

<div class="container">
  <div style="display:flex;gap:8px;align-items:center;margin:12px 0">
    <form method="get" style="flex:1;display:flex;gap:8px">
      <input type="hidden" name="db" value="<?=h($dbPath)?>">
      <input type="search" name="q" placeholder="Search filenames and contents..." value="<?=h($search)?>" style="flex:1;padding:8px;border-radius:8px;border:1px solid #123;color:#fff;background:#071223">
      <button class="btn" type="submit">Search</button>
    </form>
  </div>

  <div class="panel">
    <div class="muted" style="margin-bottom:8px">Location: <a class="muted" href="?db=<?=rawurlencode($dbPath)?>">/</a>
      <?php foreach($crumbs as $c){ if($c[1]==='') continue; echo ' / <a class="muted" href="?db='.rawurlencode($dbPath).'&path='.rawurlencode($c[0]).'">'.h($c[1]).'</a>'; } ?>
    </div>

    <table>
      <thead>
        <tr><th>Name</th><th>Type</th><th>Size</th><th>Mode</th><th>Modified</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php if($path!==''): ?>
        <tr><td colspan="6"><a class="muted" href="?db=<?=rawurlencode($dbPath)?>&path=<?=rawurlencode(parent_path($path))?>">⬅ Up to parent</a></td></tr>
        <?php endif; ?>

        <?php foreach($dirs as $name=>$row): $full = join_path($path,$name); ?>
        <tr>
          <td>📁 <a href="?db=<?=rawurlencode($dbPath)?>&path=<?=rawurlencode($full)?>"><?=h($name)?></a></td>
          <td><span class="muted">Directory</span></td>
          <td class="muted">—</td>
          <td class="muted"><?=h(format_mode($row['mode']))?></td>
          <td class="muted"><?=h(format_mtime($row['mtime']))?></td>
          <td class="muted">—</td>
        </tr>
        <?php endforeach; ?>

        <?php foreach($files as $key=>$row): $sz=(int)$row['sz']; $displayName = $row['name']; if($search!==''){ $displayName = preg_replace('/('.preg_quote($search,'/').')/i','<mark>$1</mark>', h(basename($row['name']))); } else { $displayName = h(basename($row['name'])); } ?>
        <tr>
          <td><?=($sz===-1?'🔗':'📄')?> <?= $displayName ?></td>
          <td><?= $sz===-1 ? 'Symlink' : ($sz===0 && $row['data']===null ? 'Directory' : ($sz===0 ? 'Empty file' : 'File')) ?></td>
          <td class="muted"><?=h(format_size($row['sz'],$row['data']))?></td>
          <td class="muted"><?=h(format_mode($row['mode']))?></td>
          <td class="muted"><?=h(format_mtime($row['mtime']))?></td>
          <td>
            <?php if($sz===-1): ?>
              <a class="btn" href="?db=<?=rawurlencode($dbPath)?>&action=raw&name=<?=rawurlencode($row['name'])?>&q=<?=rawurlencode($search)?>">View target</a>
            <?php else: ?>
              <a class="btn" href="?db=<?=rawurlencode($dbPath)?>&action=raw&name=<?=rawurlencode($row['name'])?>&q=<?=rawurlencode($search)?>">View raw</a>
              <a class="btn" style="background:#059669" href="?db=<?=rawurlencode($dbPath)?>&action=view&name=<?=rawurlencode($row['name'])?>">Render</a>
              <!-- a class="btn" style="background:#6b21a8" href="?db=<?=rawurlencode($dbPath)?>&action=download&name=<?=rawurlencode($row['name'])?>">Download</a -->
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>

        <?php if(empty($dirs) && empty($files)): ?><tr><td colspan="6" class="muted">No results.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php
  // Render view (Dokuwiki) when requested
  if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['name'])) {
      $name = (string)$_GET['name'];
      $stmt = $pdo->prepare('SELECT name, sz, data FROM sqlar WHERE name = :n');
      $stmt->execute([':n'=>$name]);
      $row = $stmt->fetch();
      echo '<div style="margin-top:16px">';
      if (!$row) {
          echo '<div class="panel">File not found</div>';
      } else {
          try { $content = extract_content_from_row($row); } catch (Throwable $e) { $content = ''; }
          // treat as text if reasonable
          $isText = true; // we render Dokuwiki for all text-like files as requested
          if ($isText) {
              echo '<div class="panel dokuwiki-content">';
              echo dokuwiki_to_html($content);
              echo '</div>';
              echo '<p style="margin-top:8px"><a class="muted" href="?db='.rawurlencode($dbPath).'&action=raw&name='.rawurlencode($row['name']).'&q='.rawurlencode($search).'">View raw/plain (with search highlights)</a></p>';
          }
      }
      echo '</div>';
  }
  ?>

</div>
</body>
</html>
