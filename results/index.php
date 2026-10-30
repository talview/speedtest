<?php

require_once 'telemetry_db.php';

error_reporting(0);
putenv('GDFONTPATH='.realpath('.'));

/**
 * Everything is drawn at SS times the final size and scaled down at the end.
 * GD has no antialiasing for filled shapes, so circles, rounded corners and
 * arcs would otherwise come out stepped; oversampling gives all of them smooth
 * edges from one mechanism.
 */
const SS = 3;

const WIDTH = 800;
const HEIGHT = 480;

const C_CARD = [14, 7, 32];         // --background-backup-color
const C_PANEL = [23, 16, 42];
const C_PANEL_EDGE = [42, 32, 64];
const C_TEXT = [255, 255, 255];     // --primary-text-color
const C_TEXT_DIM = [137, 133, 145]; // --secondary-text-color
const C_DOWNLOAD = [92, 249, 253];  // --theme-green
const C_UPLOAD = [214, 59, 198];    // --theme-pink

/**
 * @param string $name
 *
 * @return string|null
 */
function tryFont($name)
{
    if (is_array(imageftbbox(12, 0, $name, 'M'))) {
        return $name;
    }

    $fullPathToFont = realpath('.').'/'.$name.'.ttf';
    if (is_array(imageftbbox(12, 0, $fullPathToFont, 'M'))) {
        return $fullPathToFont;
    }

    return null;
}

/**
 * @param int|float $d
 *
 * @return string
 */
function format($d)
{
    if ($d < 10) {
        return number_format($d, 2, '.', '');
    }
    if ($d < 100) {
        return number_format($d, 1, '.', '');
    }

    return number_format($d, 0, '.', '');
}

/**
 * Drops the fractional seconds a database may keep on the timestamp column.
 *
 * How much precision that column carries is decided by the backend, and the
 * three schemas shipped here disagree: MySQL declares `timestamp`, which keeps
 * none, PostgreSQL uses `timestamp without time zone DEFAULT now()`, which
 * keeps microseconds, and MSSQL uses `datetime`, which keeps milliseconds.
 *
 * The fraction is matched against the seconds field it belongs to rather than
 * the end of the string, so a value carrying a zone offset after it, such as
 * "2026-08-10 02:04:13.957789+00", is handled as well.
 *
 * @param string $timestamp
 *
 * @return string
 */
function formatTimestamp($timestamp)
{
    return preg_replace('/(:\d{2})\.\d+/', '$1', (string) $timestamp);
}

/**
 * Splits a timestamp into a date and a time meant to be read rather than
 * parsed.
 *
 * The whole value is returned as the date with an empty time when it cannot be
 * parsed, which keeps a backend nobody here has seen legible rather than blank.
 *
 * @param string $timestamp
 *
 * @return array{0: string, 1: string}
 */
function splitTimestamp($timestamp)
{
    $timestamp = formatTimestamp($timestamp);
    $t = strtotime($timestamp);
    if (false === $t) {
        return [$timestamp, ''];
    }

    return [date('j M Y', $t), date('H:i', $t)];
}

/**
 * Names the address family a result was measured over, without disclosing the
 * address itself.
 *
 * Returns an empty string when the deployment redacts client addresses, since
 * the placeholder it stores would otherwise be reported as IPv4.
 *
 * @param string $ip
 *
 * @return string
 */
function addressFamily($ip)
{
    if ('' === $ip || '0.0.0.0' === $ip) {
        return '';
    }

    return false === strpos($ip, ':') ? 'IPv4' : 'IPv6';
}

/**
 * @param resource|GdImage $im
 * @param int[]            $rgb
 * @param int              $alpha 0 opaque, 127 transparent
 *
 * @return int
 */
function col($im, $rgb, $alpha = 0)
{
    return imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], $alpha);
}

/**
 * The horizontal span of a rounded rectangle on a given row.
 *
 * Filling and shading both need to respect the same corners, so both ask this
 * rather than each approximating the curve on its own.
 *
 * @return array{0: float, 1: float}|null
 */
function roundedSpan($y, $x1, $y1, $x2, $y2, $r)
{
    if ($y < $y1 || $y > $y2) {
        return null;
    }

    $inset = 0;
    if ($y < $y1 + $r) {
        $dy = $r - ($y - $y1);
        $inset = $r - sqrt(max(0, $r * $r - $dy * $dy));
    } elseif ($y > $y2 - $r) {
        $dy = $r - ($y2 - $y);
        $inset = $r - sqrt(max(0, $r * $r - $dy * $dy));
    }

    return [$x1 + $inset, $x2 - $inset];
}

/**
 * @param resource|GdImage $im
 *
 * @return void
 */
function roundedRect($im, $x1, $y1, $x2, $y2, $r, $color)
{
    for ($y = (int) $y1; $y <= (int) $y2; ++$y) {
        $span = roundedSpan($y, $x1, $y1, $x2, $y2, $r);
        if (null !== $span) {
            imageline($im, (int) round($span[0]), $y, (int) round($span[1]), $y, $color);
        }
    }
}

/**
 * A panel: a one-pixel edge with a darker fill inside it.
 *
 * @param resource|GdImage $im
 *
 * @return void
 */
function panel($im, $x1, $y1, $x2, $y2, $r, $fill, $edge)
{
    roundedRect($im, $x1, $y1, $x2, $y2, $r, $edge);
    roundedRect($im, $x1 + SS, $y1 + SS, $x2 - SS, $y2 - SS, $r - SS, $fill);
}

/**
 * Washes an accent colour into the bottom of a panel.
 *
 * This is deliberately a plain gradient and not a line chart. A curve inside a
 * speed test result reads as measured data, and no per-second samples are
 * stored for a shared result, so any curve drawn here would be invented.
 *
 * @param resource|GdImage $im
 * @param int[]            $rgb
 *
 * @return void
 */
function wash($im, $x1, $y1, $x2, $y2, $r, $rgb, $height, $maxAlpha = 88)
{
    $start = $y2 - $height;
    for ($y = (int) $start; $y <= (int) $y2; ++$y) {
        $span = roundedSpan($y, $x1, $y1, $x2, $y2, $r);
        if (null === $span) {
            continue;
        }
        $t = ($y - $start) / max(1, $height);
        $alpha = (int) round(127 - (127 - $maxAlpha) * ($t * $t));
        imageline($im, (int) round($span[0]), $y, (int) round($span[1]), $y, col($im, $rgb, $alpha));
    }
}

/**
 * @param string $text
 *
 * @return float
 */
function textWidth($text, $font, $size)
{
    $bbox = imageftbbox($size, 0, $font, $text);

    return is_array($bbox) ? $bbox[2] - $bbox[0] : 0;
}

/**
 * Draws text, anchored left, centre or right on x.
 *
 * @param resource|GdImage $im
 *
 * @return float the width drawn
 */
function text($im, $x, $y, $size, $font, $color, $string, $align = 'left')
{
    $w = textWidth($string, $font, $size);
    if ('center' === $align) {
        $x -= $w / 2;
    } elseif ('right' === $align) {
        $x -= $w;
    }
    imagefttext($im, $size, 0, (int) round($x), (int) round($y), $color, $font, $string);

    return $w;
}

/**
 * The width trackedText() would draw.
 *
 * @return float
 */
function trackedWidth($string, $font, $size, $tracking)
{
    $total = -$tracking;
    foreach (preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $total += textWidth($ch, $font, $size) + $tracking;
    }

    return $total;
}

/**
 * The same as text(), with the letters spaced apart.
 *
 * GD has no notion of tracking, so each character is placed on its own. The
 * small uppercase labels are unreadable without it at this size.
 *
 * @param resource|GdImage $im
 *
 * @return float
 */
function trackedText($im, $x, $y, $size, $font, $color, $string, $tracking, $align = 'left')
{
    $chars = preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
    $total = trackedWidth($string, $font, $size, $tracking);

    if ('center' === $align) {
        $x -= $total / 2;
    } elseif ('right' === $align) {
        $x -= $total;
    }

    foreach ($chars as $ch) {
        imagefttext($im, $size, 0, (int) round($x), (int) round($y), $color, $font, $ch);
        $x += textWidth($ch, $font, $size) + $tracking;
    }

    return $total;
}

/**
 * Fills a polygon on both supported PHP majors.
 *
 * imagefilledpolygon() took a point count until PHP 8.0, which deprecated it
 * and made the parameter optional. The backend already relies on the null
 * coalescing operator, so PHP 7 is the floor to keep working here.
 *
 * @param resource|GdImage $im
 * @param int[]            $points
 *
 * @return void
 */
function filledPolygon($im, $points, $color)
{
    if (PHP_VERSION_ID >= 80000) {
        imagefilledpolygon($im, $points, $color);

        return;
    }

    imagefilledpolygon($im, $points, count($points) / 2, $color);
}

/**
 * A ring with an arrow in it, marking which direction a panel measures.
 *
 * @param resource|GdImage $im
 * @param int[]            $rgb
 *
 * @return void
 */
function directionBadge($im, $cx, $cy, $r, $rgb, $pointsDown)
{
    // ring
    imagefilledellipse($im, (int) $cx, (int) $cy, (int) (2 * $r), (int) (2 * $r), col($im, $rgb, 108));
    imagefilledellipse($im, (int) $cx, (int) $cy, (int) (2 * $r - 2 * SS), (int) (2 * $r - 2 * SS), col($im, C_PANEL));

    $color = col($im, $rgb);
    $shaft = $r * 0.52;
    $head = $r * 0.34;
    $dir = $pointsDown ? 1 : -1;

    imagesetthickness($im, (int) max(1, SS * 0.7));
    imageline($im, (int) $cx, (int) ($cy - $dir * $shaft), (int) $cx, (int) ($cy + $dir * $shaft), $color);
    imagesetthickness($im, 1);

    $tipY = $cy + $dir * $shaft;
    filledPolygon($im, [
        (int) $cx, (int) ($tipY + $dir * $head * 0.5),
        (int) ($cx - $head), (int) ($tipY - $dir * $head * 0.55),
        (int) ($cx + $head), (int) ($tipY - $dir * $head * 0.55),
    ], $color);
}

/**
 * A ring with a trace in it: a spike for ping, a wave for jitter.
 *
 * @param resource|GdImage $im
 * @param int[]            $rgb
 *
 * @return void
 */
function traceBadge($im, $cx, $cy, $r, $rgb, $wave)
{
    imagefilledellipse($im, (int) $cx, (int) $cy, (int) (2 * $r), (int) (2 * $r), col($im, $rgb, 112));
    imagefilledellipse($im, (int) $cx, (int) $cy, (int) (2 * $r - 2 * SS), (int) (2 * $r - 2 * SS), col($im, C_PANEL));

    $color = col($im, $rgb);
    imagesetthickness($im, (int) max(1, SS * 0.8));

    $w = $r * 1.05;
    $prev = null;
    for ($i = 0; $i <= 48; ++$i) {
        $t = $i / 48;
        $x = $cx - $w + 2 * $w * $t;
        if ($wave) {
            $y = $cy - sin($t * 2 * M_PI) * $r * 0.42;
        } else {
            // a flat line with one spike in the middle
            $d = abs($t - 0.5);
            $y = $cy - ($d < 0.16 ? (0.16 - $d) / 0.16 * $r * 0.62 : 0) + ($d > 0.16 && $d < 0.26 ? $r * 0.2 : 0);
        }
        if (null !== $prev) {
            imageline($im, (int) $prev[0], (int) $prev[1], (int) $x, (int) $y, $color);
        }
        $prev = [$x, $y];
    }

    imagesetthickness($im, 1);
}

/**
 * Shortens text until it fits, marking it as shortened.
 *
 * User agents in particular are unbounded: a browser sends a far longer string
 * than a command line client, and drawn as-is it would run off the panel.
 *
 * Characters are dropped rather than bytes. A provider name or user agent may
 * well carry multibyte characters, and cutting one in half leaves a sequence
 * that renders as a replacement glyph rather than shortened text. The input is
 * capped first so that the measuring below is bounded no matter what is stored.
 *
 * @return string
 */
function fitText($text, $font, $size, $maxWidth)
{
    if (textWidth($text, $font, $size) <= $maxWidth) {
        return $text;
    }

    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($chars)) {
        return '…';
    }
    $chars = array_slice($chars, 0, 256);

    while (count($chars) > 0 && textWidth(implode('', $chars).'…', $font, $size) > $maxWidth) {
        array_pop($chars);
    }

    return implode('', $chars).'…';
}

/**
 * @param array $speedtest
 *
 * @return array
 */
function formatSpeedtestDataForImage($speedtest)
{
    $speedtest['dl'] = format($speedtest['dl']);
    $speedtest['ul'] = format($speedtest['ul']);
    $speedtest['ping'] = format($speedtest['ping']);
    $speedtest['jitter'] = format($speedtest['jitter']);
    $speedtest['family'] = addressFamily($speedtest['ip']);
    list($speedtest['date'], $speedtest['time']) = splitTimestamp($speedtest['timestamp']);
    // The classic design prints the timestamp whole rather than split in two.
    $speedtest['timestamp'] = formatTimestamp($speedtest['timestamp']);

    $ispinfo = json_decode($speedtest['ispinfo'], true)['processedString'];
    $dash = strpos($ispinfo, '-');
    if ($dash !== false) {
        $ispinfo = substr($ispinfo, $dash + 2);
        $par = strrpos($ispinfo, '(');
        if ($par !== false) {
            $ispinfo = substr($ispinfo, 0, $par);
        }
    } else {
        $ispinfo = '';
    }
    $speedtest['ispinfo'] = trim($ispinfo);

    return $speedtest;
}

/**
 * Draws the result image, matching the Talview page (index.html).
 *
 * @param array $speedtest
 *
 * @return void
 */
function drawClassicImage($speedtest)
{
    // format values for the image
    $data = formatSpeedtestDataForImage($speedtest);
    $dl = $data['dl'];
    $ul = $data['ul'];
    $ping = $data['ping'];
    $jit = $data['jitter'];
    $ispinfo = $data['ispinfo'];
    $timestamp = $data['timestamp'];

    // initialize the image
    $SCALE = 1.25;
    $SMALL_SEP = 8 * $SCALE;
    $WIDTH = 400 * $SCALE;
    $HEIGHT = 229 * $SCALE;
    $im = imagecreatetruecolor($WIDTH, $HEIGHT);
    $BACKGROUND_COLOR = imagecolorallocate($im, 255, 255, 255);

    // configure fonts
    $FONT_LABEL = tryFont('OpenSans-Semibold');
    $FONT_LABEL_SIZE = 14 * $SCALE;
    $FONT_LABEL_SIZE_BIG = 16 * $SCALE;

    $FONT_METER = tryFont('OpenSans-Light');
    $FONT_METER_SIZE = 20 * $SCALE;
    $FONT_METER_SIZE_BIG = 22 * $SCALE;

    $FONT_MEASURE = tryFont('OpenSans-Semibold');
    $FONT_MEASURE_SIZE = 12 * $SCALE;
    $FONT_MEASURE_SIZE_BIG = 12 * $SCALE;

    $FONT_ISP = tryFont('OpenSans-Semibold');
    $FONT_ISP_SIZE = 9 * $SCALE;

    $FONT_TIMESTAMP = tryFont("OpenSans-Light");
    $FONT_TIMESTAMP_SIZE = 8 * $SCALE;

    $FONT_WATERMARK = tryFont('OpenSans-Light');
    $FONT_WATERMARK_SIZE = 8 * $SCALE;

    // configure text colors
    $TEXT_COLOR_LABEL = imagecolorallocate($im, 40, 40, 40);
    $TEXT_COLOR_PING_METER = imagecolorallocate($im, 170, 96, 96);
    $TEXT_COLOR_JIT_METER = imagecolorallocate($im, 170, 96, 96);
    $TEXT_COLOR_DL_METER = imagecolorallocate($im, 96, 96, 170);
    $TEXT_COLOR_UL_METER = imagecolorallocate($im, 96, 96, 96);
    $TEXT_COLOR_MEASURE = imagecolorallocate($im, 40, 40, 40);
    $TEXT_COLOR_ISP = imagecolorallocate($im, 40, 40, 40);
    $SEPARATOR_COLOR = imagecolorallocate($im, 192, 192, 192);
    $TEXT_COLOR_TIMESTAMP = imagecolorallocate($im, 160, 160, 160);
    $TEXT_COLOR_WATERMARK = imagecolorallocate($im, 160, 160, 160);

    // configure positioning or the different parts on the image
    $POSITION_X_PING = 125 * $SCALE;
    $POSITION_Y_PING_LABEL = 24 * $SCALE;
    $POSITION_Y_PING_METER = 60 * $SCALE;
    $POSITION_Y_PING_MEASURE = 60 * $SCALE;

    $POSITION_X_JIT = 275 * $SCALE;
    $POSITION_Y_JIT_LABEL = 24 * $SCALE;
    $POSITION_Y_JIT_METER = 60 * $SCALE;
    $POSITION_Y_JIT_MEASURE = 60 * $SCALE;

    $POSITION_X_DL = 120 * $SCALE;
    $POSITION_Y_DL_LABEL = 105 * $SCALE;
    $POSITION_Y_DL_METER = 143 * $SCALE;
    $POSITION_Y_DL_MEASURE = 169 * $SCALE;

    $POSITION_X_UL = 280 * $SCALE;
    $POSITION_Y_UL_LABEL = 105 * $SCALE;
    $POSITION_Y_UL_METER = 143 * $SCALE;
    $POSITION_Y_UL_MEASURE = 169 * $SCALE;

    $POSITION_X_ISP = 4 * $SCALE;
    $POSITION_Y_ISP = 205 * $SCALE;

    $SEPARATOR_Y = 211 * $SCALE;

    $POSITION_X_TIMESTAMP= 4 * $SCALE;
    $POSITION_Y_TIMESTAMP = 223 * $SCALE;

    $POSITION_Y_WATERMARK = 223 * $SCALE;

    // configure labels
    $MBPS_TEXT = 'Mbit/s';
    $MS_TEXT = 'ms';
    $PING_TEXT = 'Ping';
    $JIT_TEXT = 'Jitter';
    $DL_TEXT = 'Download';
    $UL_TEXT = 'Upload';
    $WATERMARK_TEXT = 'LibreSpeed';

    // create text boxes for each part of the image
    $mbpsBbox = imageftbbox($FONT_MEASURE_SIZE_BIG, 0, $FONT_MEASURE, $MBPS_TEXT);
    $msBbox = imageftbbox($FONT_MEASURE_SIZE, 0, $FONT_MEASURE, $MS_TEXT);
    $pingBbox = imageftbbox($FONT_LABEL_SIZE, 0, $FONT_LABEL, $PING_TEXT);
    $pingMeterBbox = imageftbbox($FONT_METER_SIZE, 0, $FONT_METER, $ping);
    $jitBbox = imageftbbox($FONT_LABEL_SIZE, 0, $FONT_LABEL, $JIT_TEXT);
    $jitMeterBbox = imageftbbox($FONT_METER_SIZE, 0, $FONT_METER, $jit);
    $dlBbox = imageftbbox($FONT_LABEL_SIZE_BIG, 0, $FONT_LABEL, $DL_TEXT);
    $dlMeterBbox = imageftbbox($FONT_METER_SIZE_BIG, 0, $FONT_METER, $dl);
    $ulBbox = imageftbbox($FONT_LABEL_SIZE_BIG, 0, $FONT_LABEL, $UL_TEXT);
    $ulMeterBbox = imageftbbox($FONT_METER_SIZE_BIG, 0, $FONT_METER, $ul);
    $watermarkBbox = imageftbbox($FONT_WATERMARK_SIZE, 0, $FONT_WATERMARK, $WATERMARK_TEXT);
    $POSITION_X_WATERMARK = $WIDTH - $watermarkBbox[4] - 4 * $SCALE;

    // put the parts together to draw the image
    imagefilledrectangle($im, 0, 0, $WIDTH, $HEIGHT, $BACKGROUND_COLOR);
    // ping
    imagefttext($im, $FONT_LABEL_SIZE, 0, $POSITION_X_PING - $pingBbox[4] / 2, $POSITION_Y_PING_LABEL, $TEXT_COLOR_LABEL, $FONT_LABEL, $PING_TEXT);
    imagefttext($im, $FONT_METER_SIZE, 0, $POSITION_X_PING - $pingMeterBbox[4] / 2 - $msBbox[4] / 2 - $SMALL_SEP / 2, $POSITION_Y_PING_METER, $TEXT_COLOR_PING_METER, $FONT_METER, $ping);
    imagefttext($im, $FONT_MEASURE_SIZE, 0, $POSITION_X_PING + $pingMeterBbox[4] / 2 + $SMALL_SEP / 2 - $msBbox[4] / 2, $POSITION_Y_PING_MEASURE, $TEXT_COLOR_MEASURE, $FONT_MEASURE, $MS_TEXT);
    // jitter
    imagefttext($im, $FONT_LABEL_SIZE, 0, $POSITION_X_JIT - $jitBbox[4] / 2, $POSITION_Y_JIT_LABEL, $TEXT_COLOR_LABEL, $FONT_LABEL, $JIT_TEXT);
    imagefttext($im, $FONT_METER_SIZE, 0, $POSITION_X_JIT - $jitMeterBbox[4] / 2 - $msBbox[4] / 2 - $SMALL_SEP / 2, $POSITION_Y_JIT_METER, $TEXT_COLOR_JIT_METER, $FONT_METER, $jit);
    imagefttext($im, $FONT_MEASURE_SIZE, 0, $POSITION_X_JIT + $jitMeterBbox[4] / 2 + $SMALL_SEP / 2 - $msBbox[4] / 2, $POSITION_Y_JIT_MEASURE, $TEXT_COLOR_MEASURE, $FONT_MEASURE, $MS_TEXT);
    // dl
    imagefttext($im, $FONT_LABEL_SIZE_BIG, 0, $POSITION_X_DL - $dlBbox[4] / 2, $POSITION_Y_DL_LABEL, $TEXT_COLOR_LABEL, $FONT_LABEL, $DL_TEXT);
    imagefttext($im, $FONT_METER_SIZE_BIG, 0, $POSITION_X_DL - $dlMeterBbox[4] / 2, $POSITION_Y_DL_METER, $TEXT_COLOR_DL_METER, $FONT_METER, $dl);
    imagefttext($im, $FONT_MEASURE_SIZE_BIG, 0, $POSITION_X_DL - $mbpsBbox[4] / 2, $POSITION_Y_DL_MEASURE, $TEXT_COLOR_MEASURE, $FONT_MEASURE, $MBPS_TEXT);
    // ul
    imagefttext($im, $FONT_LABEL_SIZE_BIG, 0, $POSITION_X_UL - $ulBbox[4] / 2, $POSITION_Y_UL_LABEL, $TEXT_COLOR_LABEL, $FONT_LABEL, $UL_TEXT);
    imagefttext($im, $FONT_METER_SIZE_BIG, 0, $POSITION_X_UL - $ulMeterBbox[4] / 2, $POSITION_Y_UL_METER, $TEXT_COLOR_UL_METER, $FONT_METER, $ul);
    imagefttext($im, $FONT_MEASURE_SIZE_BIG, 0, $POSITION_X_UL - $mbpsBbox[4] / 2, $POSITION_Y_UL_MEASURE, $TEXT_COLOR_MEASURE, $FONT_MEASURE, $MBPS_TEXT);
    // isp
    imagefttext($im, $FONT_ISP_SIZE, 0, $POSITION_X_ISP, $POSITION_Y_ISP, $TEXT_COLOR_ISP, $FONT_ISP, $ispinfo);
    // separator
    imagefilledrectangle($im, 0, $SEPARATOR_Y, $WIDTH, $SEPARATOR_Y, $SEPARATOR_COLOR);
    // timestamp
    imagefttext($im, $FONT_TIMESTAMP_SIZE, 0, $POSITION_X_TIMESTAMP, $POSITION_Y_TIMESTAMP, $TEXT_COLOR_TIMESTAMP, $FONT_TIMESTAMP, $timestamp);
    // watermark
    imagefttext($im, $FONT_WATERMARK_SIZE, 0, $POSITION_X_WATERMARK, $POSITION_Y_WATERMARK, $TEXT_COLOR_WATERMARK, $FONT_WATERMARK, $WATERMARK_TEXT);

    // send the image to the browser
    header('Content-Type: image/png');
    imagepng($im);
}

$speedtest = getSpeedtestUserById($_GET['id']);
if (!is_array($speedtest)) {
    exit(1);
}

drawClassicImage($speedtest);
