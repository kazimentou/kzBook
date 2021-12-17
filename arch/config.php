<?php
if (!defined('PLX_ROOT')) {
	exit;
}

plxToken::validateFormToken();

$plxMotor = plxMotor::getInstance();

$groups = array_unique(array_map(function($v) {
	return trim($v['group']);
}, $plxMotor->aStats));
sort($groups);

$templates = array_unique(array_map(function($v) {
	return trim(basename($v['template'], '.php'));
}, $plxMotor->aStats));
sort($templates);

$params = filter_input_array(INPUT_POST, array(
	'stats' => array(
		'flags'		=> FILTER_REQUIRE_ARRAY,
		'filter'	=> FILTER_VALIDATE_REGEXP,
		'options'	=> array('regexp' => '#^\d{3}$#'),
	),
	'group'	=> array(
		'filter'	=> FILTER_VALIDATE_REGEXP,
		'options'	=> array('regexp' => '#^(?:' . implode('|', $groups) . ')?$#')
	),
	'template'	=> array(
		'filter'	=> FILTER_VALIDATE_REGEXP,
		'options'	=> array('regexp' => '#^(?:' . implode('|', $templates) . ')?$#')
	)
));
if (!empty($params)) {
	$stats = array_filter($plxMotor->aStats, function($aStat, $id) use($params) {
		# $params['group'], $params['template'] et $params['stats'] ne peuvent être tous nuls sinon $params est vide
		return (
			(empty($params['group']) or $params['group'] == $aStat['group']) and
			(empty($params['template']) or $params['template'] == basename($aStat['template'], '.php')) and
			(empty($params['stats']) or in_array($id, $params['stats']))
		);
	},  ARRAY_FILTER_USE_BOTH);
	if(!empty($stats)) {
		foreach(array('group', 'template', 'stats') as $f) {
			if (!empty($params[$f])) {
				$plxPlugin->setParam($f, is_array($params[$f]) ? implode(',',$params[$f]) : $params[$f], 'string');
			} else {
				$plxPlugin->delParam($f);
			}
		}
		$plxPlugin->saveParams();
		# On génére le livre
		# $plxPlugin->_build($stats);
	}
	header('Location: parametres_plugin.php?p=' . $plugin);
	exit;
}
?>
<form class="<?= $plugin ?>" method="post">
	<div>
		<div>
			<label>
				<span>Groupe</span>
				<select name="group" onchange="onHide(this.form);">
					<option value="">Tous</option>
<?php
$value = $plxPlugin->getParam('group');
foreach($groups as $g) {
	if(!empty($g)) {
		$selected = ($g == $value) ? ' selected' : '';
?>
					<option<? $selected ?>><?= $g ?></option>
<?php
	}
}
?>
				</select>
			</label>
		</div>
		<div>
			<label>
				<span>Gabarit</span>
				<select name="template" onchange="onHide(this.form);">
					<option value="">Tous</option>
<?php
$value = $plxPlugin->getParam('template');
foreach($templates as $t) {
	$selected = ($g == $value) ? ' selected' : '';
?>
					<option value="<?= $t ?>"<?= $selected ?>><?= $t ?></option>
<?php
}
?>
				</select>
			</label>
		</div>
	</div>
	<table>
		<thead>
			<tr>
				<th><input type="checkbox" onclick="onToggle(this, 'stats[]');" /></th>
				<th>#</th>
				<th>Groupe</th>
				<th>Nom</th>
				<th>Titre</th>
				<th>Gabarit</th>
			</tr>
		</thead>
		<tbody>
<?php
$ids = explode(',', $plxPlugin->getParam('stats'));
foreach($plxMotor->aStats as $k=>$v) {
	if(!empty($v['active'])) {
		$checked = in_array($k, $ids) ? ' checked' : '';
?>
			<tr>
				<td><input type="checkbox" name="stats[]" value="<?= $k ?>"<?= $checked ?> /></td>
				<td><?= $k ?></td>
				<td><?= $v['group'] ?></td>
				<td><?= $v['name'] ?></td>
				<td><?= $v['title_htmltag'] ?></td>
				<td><?= basename($v['template'], '.php') ?></td>
			</tr>
<?php
	}
}
?>
		</tbody>
	</table>
	<div>
		<input type="submit" />
		<?= plxToken::getTokenPostMethod() ?>
	</div>
</form>
<script type="text/javascript">
function onToggle(el, name) {
	// use strict;
	const nodes = el.form.elements[name];
	if (nodes) {
		for(var i=0, iMax = nodes.length; i < iMax; i++) {
			if (nodes[i].type == 'checkbox') {
				nodes[i].checked = el.checked;
			}
		}
	}
}

function onHide(aForm) {
	const group = aForm.elements['group'].value.trim();
	const template = aForm.elements['template'].value.trim();
	const rows = aForm.querySelectorAll('tbody:first-of-type tr');
	for(var i=0, iMax=rows.length; i<iMax; i++) {
		if (
			(
				group.length == 0 ||
				group == rows[i].cells[2].textContent
			) && (
				template.length == 0 ||
				template == rows[i].cells[5].textContent
			)
		) {
			rows[i].classList.remove('hide');
		} else {
			rows[i].classList.add('hide');
		}
	}
}
</script>
