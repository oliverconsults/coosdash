<?php
// Shared Fortschritt (stündlich) chart.
// Expects: $metricsRows (array)

if (empty($metricsRows) || count($metricsRows) < 2) return;

$w = 320; $h = 92;
$pTodoJ = array_map(fn($r) => (int)$r['projects_todo_james'], $metricsRows);
$pDone  = array_map(fn($r) => (int)$r['projects_done'], $metricsRows);
$pTodoO = array_map(fn($r) => (int)$r['projects_todo_oliver'], $metricsRows);
$pBlocked = array_map(fn($r) => (int)$r['projects_blocked'], $metricsRows);

$totals = [];
for ($i=0; $i<count($metricsRows); $i++) {
  $totals[] = $pDone[$i] + $pBlocked[$i] + $pTodoJ[$i] + $pTodoO[$i];
}
$totalMax = max(1, max($totals));
$pad = 8;
$x0 = $pad; $y0 = $pad; $x1 = $w - $pad; $y1 = $h - $pad;
$n = count($metricsRows);
$xs = [];
for ($i=0; $i<$n; $i++) $xs[] = ($n===1) ? $x0 : ($x0 + ($x1-$x0) * ($i/($n-1)));

$yFor = function(int $i, int $sum) use ($y0,$y1,$totalMax): float {
  $t = $sum / $totalMax;
  return $y1 - ($y1-$y0) * $t;
};

$areas = [
  ['key'=>'done','label'=>'Done','color'=>'rgba(28, 115, 70, 0.78)'],
  ['key'=>'blocked','label'=>'Blocked','color'=>'rgba(255,120,120,0.55)'],
  ['key'=>'todo_j','label'=>'ToDo (J)','color'=>'rgba(212,175,55,0.62)'], 
  ['key'=>'todo_o','label'=>'ToDo (O)','color'=>'rgba(120,200,255,0.55)'],
];

$series = [
  'done' => $pDone,
  'blocked' => $pBlocked,
  'todo_j' => $pTodoJ,
  'todo_o' => $pTodoO,
];

$makeAreaPath = function(array $stackBelow, array $values) use ($xs,$yFor,$n): string {
  $topPts = [];
  $botPts = [];
  for ($i=0; $i<$n; $i++) {
    $below = (int)$stackBelow[$i];
    $val = (int)$values[$i];
    $topSum = $below + $val;
    $topPts[] = [$xs[$i], $yFor($i, $topSum)];
    $botPts[] = [$xs[$i], $yFor($i, $below)];
  }
  $d = 'M ' . number_format($topPts[0][0],2,'.','') . ' ' . number_format($topPts[0][1],2,'.','');
  for ($i=1; $i<$n; $i++) $d .= ' L ' . number_format($topPts[$i][0],2,'.','') . ' ' . number_format($topPts[$i][1],2,'.','');
  for ($i=$n-1; $i>=0; $i--) $d .= ' L ' . number_format($botPts[$i][0],2,'.','') . ' ' . number_format($botPts[$i][1],2,'.','');
  $d .= ' Z';
  return $d;
};

$tsFirst = strtotime((string)$metricsRows[0]['ts']);
$midIdx = (int)floor(($n-1)/2);
$tsMid = strtotime((string)$metricsRows[$midIdx]['ts']);
$tsLast = strtotime((string)$metricsRows[$n-1]['ts']);
?>

<div class="note" style="margin-top:12px">
  <div class="head">Fortschritt (stündlich)</div>

  <div class="row" style="gap:10px; flex-wrap:wrap; margin-bottom:6px;">
    <?php foreach ($areas as $a): ?>
      <span class="meta" style="display:inline-flex; align-items:center; gap:6px;">
        <span style="display:inline-block; width:10px; height:10px; border-radius:3px; background:<?php echo h($a['color']); ?>; border:1px solid rgba(255,255,255,0.25);"></span>
        <?php echo h($a['label']); ?>
      </span>
    <?php endforeach; ?>
  </div>

  <svg viewBox="0 0 <?php echo (int)$w; ?> <?php echo (int)$h; ?>" width="100%" height="<?php echo (int)$h; ?>" preserveAspectRatio="none" style="display:block; background:linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02)); border-radius:10px;">
    <?php
      // hourly gridlines
      for ($i=0; $i<$n; $i++) {
        $x = $xs[$i];
        echo '<line x1="' . h(number_format($x,2,'.','')) . '" y1="' . (int)$y0 . '" x2="' . h(number_format($x,2,'.','')) . '" y2="' . (int)$y1 . '" stroke="rgba(255,255,255,0.06)" stroke-width="1" />';
      }

      // midpoint marker
      $xMid = $xs[$midIdx] ?? null;
      if ($xMid !== null) {
        echo '<line x1="' . h(number_format($xMid,2,'.','')) . '" y1="' . (int)$y0 . '" x2="' . h(number_format($xMid,2,'.','')) . '" y2="' . (int)$y1 . '" stroke="rgba(255,255,255,0.22)" stroke-width="1" stroke-dasharray="4 4" />';
      }

      $stack = array_fill(0, $n, 0);
      foreach ($areas as $a) {
        $k = $a['key'];
        $d = $makeAreaPath($stack, $series[$k]);
        echo '<path d="' . h($d) . '" fill="' . h($a['color']) . '" stroke="rgba(255,255,255,0.15)" stroke-width="0.5" />';
        for ($i=0; $i<$n; $i++) $stack[$i] += (int)$series[$k][$i];
      }
    ?>
  </svg>

  <div class="row" style="justify-content:space-between; margin-top:6px;">
    <span class="meta"><?php echo h(date('d.m H:i', $tsFirst)); ?></span>
    <span class="meta"><?php echo h(date('d.m H:i', $tsMid)); ?></span>
    <span class="meta"><?php echo h(date('d.m H:i', $tsLast)); ?></span>
  </div>
</div>
