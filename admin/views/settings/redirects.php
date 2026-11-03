<?php $rows = \Slate\Options::get('redirects') ?: []; $rows[] = ['from' => '', 'to' => '', 'code' => 301, 'regex' => false]; ?>
<div class="card-pad">
  <p class="muted">Send old URLs to new ones. Use <code>410</code> for removed pages. Regex rules can use <code>$1</code> in the target, e.g. <code>^/old-blog/(.*)$</code> → <code>/blog/$1/</code>.</p>
  <div class="table-wrap"><table class="table table-form" data-repeat-table>
    <thead><tr><th>From (path)</th><th>To (path or URL)</th><th>Type</th><th class="center">Regex</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
      <tr>
        <td><input class="input input-sm" name="redirects[from][]" value="<?= e($r['from']) ?>" placeholder="/old-page/"></td>
        <td><input class="input input-sm" name="redirects[to][]" value="<?= e($r['to']) ?>" placeholder="/new-page/"></td>
        <td><select class="input input-sm" name="redirects[code][]"><?php foreach ([301 => '301 Permanent', 302 => '302 Temporary', 307 => '307 Temporary', 308 => '308 Permanent', 410 => '410 Gone'] as $c => $l): ?><option value="<?= $c ?>"<?= (int)$r['code'] === $c ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></td>
        <td class="center"><input type="hidden" name="redirects[regex][]" value="0"><input type="checkbox" value="1" <?= !empty($r['regex']) ? 'checked' : '' ?> onchange="this.previousElementSibling.value=this.checked?1:0"></td>
        <td><button type="button" class="icon-btn" data-remove-row aria-label="Remove"><?= icon('trash', 15) ?></button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <button type="button" class="btn btn-sm" data-add-row><?= icon('plus', 15) ?> Add redirect</button>
</div>
