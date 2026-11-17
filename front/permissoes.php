<?php

use GlpiPlugin\Escalations\Engine;
use GlpiPlugin\Escalations\Escalation;
use GlpiPlugin\Escalations\Menu;
use GlpiPlugin\Escalations\Perms;
use GlpiPlugin\Escalations\Policy;
use GlpiPlugin\Escalations\Ui;

Menu::guard(false, true);
global $DB;
$self = Menu::url('/front/permissoes.php');
$me = (int) Session::getLoginUserID();
$policies = array_column(Policy::all(), 'name', 'id');

// ------------------------------------------------------------------ gravação (o redirecionamento fica fora do try/catch)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $go = $self;
    $msg = '';
    $err = '';
    try {
        switch ((string) ($_POST['action'] ?? '')) {
            case 'profiles_save':
                $n = $changed = 0;
                $errors = [];
                foreach ((array) ($_POST['prof'] ?? []) as $pid => $d) {
                    $pid = (int) $pid;
                    try {
                        $changed += Perms::saveProfileRights($pid, array_keys(array_filter((array) ($d['r'] ?? []))), $me) ? 1 : 0;
                    } catch (RuntimeException $x) {
                        $errors[] = (Perms::profiles(true)[$pid] ?? ('#' . $pid)) . ': ' . $x->getMessage();
                    }
                    Perms::saveProfile($pid, ['notify_only' => !empty($d['notify']), 'scope' => (array) ($d['scope'] ?? [])], $me);
                    $n++;
                }
                $msg = 'Perfis salvos: ' . $changed . ' com direitos alterados (' . $n . ' conferidos).';
                if ($errors) {
                    throw new RuntimeException(implode(' | ', $errors) . ' — os demais perfis foram salvos.', 422);
                }
                break;
            case 'user_save':
                $uid = (int) ($_POST['user_id'] ?? 0);
                if (!$uid || !isset(Ui::users()[$uid])) {
                    throw new RuntimeException('Escolha o usuário.', 422);
                }
                Perms::saveUser($uid, ['cap' => (array) ($_POST['cap'] ?? []), 'notify_only' => !empty($_POST['notify_only']), 'scope' => (array) ($_POST['scope'] ?? []), 'note' => (string) ($_POST['note'] ?? '')], $me);
                $msg = 'Permissões de ' . Ui::userName($uid) . ' salvas.';
                break;
            case 'user_delete':
                Perms::deleteUser((int) ($_POST['user_id'] ?? 0), $me);
                $msg = 'Exceções removidas: volta a valer o perfil.';
                break;
            default:
                throw new RuntimeException('Ação desconhecida.', 422);
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
        $go = $self . (!empty($_POST['user_id']) ? '?user=' . (int) $_POST['user_id'] : '');
    }
    Session::addMessageAfterRedirect($err !== '' ? $err : $msg, false, $err !== '' ? ERROR : INFO);
    Html::redirect($go);
}

Menu::header('Permissões', 'permissoes');
$yes = "<i class='ti ti-check text-success'></i>";
$no = "<span class='text-secondary'>—</span>";
$caps = Perms::caps();
$short = ['view' => 'Ver', 'ack' => 'ACK', 'resolve' => 'Resolver', 'silence' => 'Silenciar', 'cancel' => 'Cancelar', 'logs' => 'Logs', 'slo' => 'SLO/gaps', 'all' => 'Ver todos'];

echo "<div class='esc-dash'>";
echo Ui::title('Permissões', 'Quem pode ver, reconhecer, resolver, silenciar e cancelar; quem é apenas notificado; e as exceções por perfil e por usuário.');
echo "<div class='card card-body mb-3'><div class='small'><b>Como as permissões se combinam</b> (da mais geral para a mais específica):"
    . "<ol class='mb-1 mt-1'><li><b>Perfil</b> (tabela abaixo; é a mesma configuração da aba Escalations em Administração → Perfis, mas editada aqui): os direitos de cada ação. “Todas as ações” (Atualizar) vale como todas juntas.</li>"
    . '<li><b>Perfil no plugin</b> (abaixo): “somente notificado” e o <i>escopo</i> de hierarquias em que os direitos do perfil valem.</li>'
    . '<li><b>Usuário no plugin</b> (abaixo): exceções por pessoa: permitir ou negar cada ação, “somente notificado” e escopo próprio.</li>'
    . '<li><b>Papel no nível</b> (Hierarquias): quem está num nível como <i>escalado</i> pode agir no escalonamento em que foi acionado; quem está como <i>somente notificado</i> recebe os avisos, mas não ganha ação por isso.</li></ol>'
    . '<b>Regra de decisão:</b> negar do usuário vence tudo; “somente notificado” (usuário ou perfil) bloqueia as ações; senão vale o direito do perfil (ou a permissão dada ao usuário) dentro do escopo; senão vale ter sido acionado como escalado naquele escalonamento (exceto cancelar). '
    . '“Administrar” e “Criar” vêm só do perfil. Quem só deve ser notificado nem precisa de acesso ao GLPI: recebe por e-mail, WhatsApp e Teams.</div></div>';

// ------------------------------------------------------------------ perfis (direitos editáveis aqui, sem passar pela tela de Perfis do GLPI)
$rights = Perms::rightBits();
$presets = [];
foreach (Perms::presets() as $key => [$label, $on, $notify]) {
    $presets[$key] = ['on' => $on, 'notify' => $notify];
}
echo Ui::formOpen($self, 'card mb-3') . "<input type='hidden' name='action' value='profiles_save'>"
    . "<div class='card-header'><h3 class='card-title'>Perfis: direitos e configuração do plugin</h3></div><div class='table-responsive'><table class='table table-vcenter card-table table-sm esc-perfis'><thead><tr><th>Perfil</th><th style='min-width:170px'>Modelo</th>";
foreach ($rights as $k => $m) {
    echo '<th class="text-center" title="' . Ui::e($m[1]) . '">' . Ui::e($m[0]) . '</th>';
}
echo "<th>Somente notificado</th><th style='min-width:210px'>Escopo de hierarquias</th></tr></thead><tbody>";
$presetOptions = ['' => 'Aplicar modelo...'] + array_map(fn ($pz) => $pz[0], Perms::presets());
foreach (Perms::profiles() as $pid => $name) {
    $bits = Perms::profileBits($pid);
    $cfg = Perms::profileCfg($pid);
    echo '<tr><td><b>' . Ui::e($name) . '</b></td><td>'
        . "<select class='form-select form-select-sm' data-preset-select data-presets='" . Ui::e(json_encode($presets, JSON_UNESCAPED_UNICODE)) . "'>";
    foreach ($presetOptions as $k => $label) {
        echo "<option value='" . Ui::e($k) . "'>" . Ui::e($label) . '</option>';
    }
    echo '</select></td>';
    foreach ($rights as $k => $m) {
        echo '<td class="text-center"><input class="form-check-input" type="checkbox" data-cap="' . Ui::e($k) . '" name="prof[' . (int) $pid . '][r][' . Ui::e($k) . ']" value="1"' . (($bits & $m[2]) ? ' checked' : '') . ' title="' . Ui::e($m[1]) . '"></td>';
    }
    echo "<td><label class='form-check'><input class='form-check-input' type='checkbox' data-notify name='prof[" . (int) $pid . "][notify]' value='1'" . (!empty($cfg['notify_only']) ? ' checked' : '') . "><span class='form-check-label small'>só recebe avisos</span></label></td>"
        . '<td>' . Ui::multi('prof[' . (int) $pid . '][scope]', $policies, $cfg['scope'] ?? [], 3) . '</td></tr>';
}
echo '</tbody></table></div><div class="card-footer d-flex justify-content-between align-items-center gap-3"><div class="small text-secondary">'
    . 'Passe o mouse no título da coluna para ver o que cada direito faz. “Todas as ações” (Atualizar) vale como ACK, Resolver, Silenciar e Cancelar juntos. “Modelo” preenche a linha; confira e salve. '
    . 'Escopo vazio = todas as hierarquias. “Somente notificado” bloqueia as ações para todo o perfil. Só perfis da interface central aparecem aqui. '
    . 'Não dá para tirar a administração do perfil que você está usando nem do último perfil administrador.</div>'
    . Ui::submit('Salvar perfis', 'action', 'profiles_save', 'ti-device-floppy') . '</div>';
Ui::closeForm();

// ------------------------------------------------------------------ exceções por usuário
$all = Perms::allUserCfg();
$editId = (int) ($_GET['user'] ?? 0);
$edit = $editId ? ($all[$editId] ?? ['grant' => 0, 'deny' => 0, 'notify_only' => false, 'scope' => [], 'note' => '']) : null;
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Exceções por usuário</h3></div><div class='table-responsive'><table class='table table-vcenter card-table table-sm'>"
    . '<thead><tr><th>Usuário</th><th>Situação</th><th>Permitir</th><th>Negar</th><th>Escopo</th><th>Observação</th><th></th></tr></thead><tbody>';
foreach ($all as $uid => $c) {
    $g = $d = [];
    foreach (Perms::OVERRIDABLE as $k) {
        if ($c['grant'] & $caps[$k][1]) {
            $g[] = $short[$k];
        }
        if ($c['deny'] & $caps[$k][1]) {
            $d[] = $short[$k];
        }
    }
    echo '<tr><td>' . Ui::avatar(Ui::userName($uid)) . ' ' . Ui::e(Ui::userName($uid)) . '</td><td>' . ($c['notify_only'] ? Ui::badge('Somente notificado', '#6366f1', 'ti-bell') : Ui::badge('Exceções', '#64748b')) . '</td>'
        . '<td>' . Ui::e(implode(', ', $g) ?: '—') . '</td><td>' . Ui::e(implode(', ', $d) ?: '—') . '</td>'
        . '<td class="small">' . Ui::e($c['scope'] ? implode(', ', array_map(fn ($p) => $policies[$p] ?? ('#' . $p), $c['scope'])) : 'todas') . '</td><td class="small">' . Ui::e($c['note']) . '</td>'
        . '<td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="' . Ui::e($self . '?user=' . (int) $uid . '#form') . '"><i class="ti ti-edit"></i></a> ';
    echo Ui::formOpen($self, 'd-inline') . "<input type='hidden' name='user_id' value='" . (int) $uid . "'>" . Ui::submit('', 'action', 'user_delete', 'ti-trash', 'btn-sm btn-outline-danger', "data-confirm='Remover as exceções deste usuário? Volta a valer o perfil.' title='Remover'");
    Ui::closeForm();
    echo '</td></tr>';
}
echo $all ? '' : "<tr><td colspan='7' class='text-center text-secondary'>Nenhuma exceção: todos seguem o perfil.</td></tr>";
echo '</tbody></table></div></div>';

echo "<div class='card mb-3' id='form'>" . Ui::formOpen($self) . "<div class='card-header'><h3 class='card-title'>" . ($edit ? 'Editar exceções de ' . Ui::e(Ui::userName($editId)) : 'Adicionar exceção para um usuário') . '</h3></div>'
    . "<input type='hidden' name='action' value='user_save'><div class='card-body'><div class='row g-3'>"
    . Ui::field('Usuário', $edit ? "<input type='hidden' name='user_id' value='" . $editId . "'>" . Ui::input('', Ui::userName($editId), 'text', 'disabled') : Ui::select('user_id', Ui::users(), '', 'required', 'Escolha...'), 'col-md-5')
    . "<div class='col-md-7'><label class='form-label'>&nbsp;</label>" . Ui::check('notify_only', (bool) ($edit['notify_only'] ?? false), 'Somente notificado: recebe os avisos, mas não pode agir (bloqueia as ações mesmo que o perfil permita)') . '</div>';
foreach (Perms::OVERRIDABLE as $k) {
    $state = $edit ? (($edit['deny'] & $caps[$k][1]) ? 'deny' : (($edit['grant'] & $caps[$k][1]) ? 'grant' : '')) : '';
    echo Ui::field($caps[$k][0], Ui::select('cap[' . $k . ']', ['grant' => 'Permitir', 'deny' => 'Negar'], $state, '', 'Herdar do perfil'), 'col-md-3');
}
echo Ui::field('Escopo de hierarquias (vazio = herda do perfil / todas)', Ui::multi('scope', $policies, $edit['scope'] ?? [], 4), 'col-md-6', 'Limita onde os direitos do perfil e as permissões dadas valem. Ser acionado como escalado continua valendo no escalonamento dele.')
    . Ui::field('Observação (por que esta exceção?)', Ui::input('note', $edit['note'] ?? '', 'text', "maxlength='255'"), 'col-md-6')
    . "</div></div><div class='card-footer text-end'>" . ($edit ? Ui::button('Cancelar', $self, 'ti-x', 'btn-outline-secondary') . ' ' : '') . Ui::submit('Salvar exceções', 'action', 'user_save', 'ti-device-floppy');
Ui::closeForm();
echo '</div>';

// ------------------------------------------------------------------ permissões efetivas de quem aparece nas hierarquias
$allProfiles = Perms::profiles(true);
$dummy = ['id' => 0, 'source' => 'manual', 'items_id' => 0];
$people = []; // uid => lista de [hierarquia, nível, papel]
foreach (Policy::all(true) as $p) {
    foreach (Policy::levels((int) $p['id']) as $pos => $l) {
        foreach (Engine::recipientRoles($dummy, $l) as $uid => $role) {
            $people[$uid][] = [$p['name'], (int) $pos, $role];
        }
    }
}
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Permissões efetivas de quem aparece nas hierarquias</h3></div><div class='table-responsive'><table class='table table-vcenter card-table table-sm'>"
    . "<thead><tr><th>Usuário</th><th>Perfis</th><th>Onde aparece</th><th class='text-center'>ACK</th><th class='text-center'>Resolver</th><th class='text-center'>Silenciar</th><th class='text-center'>Cancelar</th><th class='text-center'>Logs</th><th class='text-center'>SLO/gaps</th><th class='text-center'>Ver todos</th><th>Observação</th></tr></thead><tbody>";
foreach ($people as $uid => $where) {
    $eff = Perms::effective($uid);
    $cfg = $eff['cfg'];
    $profNames = [];
    foreach (Perms::profileIds($uid) as $pid) {
        $profNames[] = $allProfiles[$pid] ?? ('#' . $pid);
    }
    $acts = (bool) array_filter($where, fn ($w) => $w[2] === 'act');
    $cell = function (string $cap) use ($eff, $cfg, $caps, $acts, $yes, $no): string {
        if ($cfg && ($cfg['deny'] & $caps[$cap][1])) {
            return "<span class='text-danger' title='Negado nas exceções do usuário'><i class='ti ti-ban'></i></span>";
        }
        if (in_array($cap, Perms::ACTIONS, true) && $eff['notify_only']) {
            return "<span class='text-secondary' title='Somente notificado'>—</span>";
        }
        if ($eff['caps'][$cap]) {
            return $yes . ' <span class="small text-secondary">' . ($cfg && ($cfg['grant'] & $caps[$cap][1]) ? 'exceção' : 'perfil') . '</span>';
        }
        if ($acts && in_array($cap, ['ack', 'resolve', 'silence'], true)) {
            return $yes . ' <span class="small text-secondary">como escalado</span>';
        }
        return $no;
    };
    $obs = [];
    if ($eff['notify_only']) {
        $obs[] = 'somente notificado' . ($cfg && $cfg['notify_only'] ? ' (exceção do usuário)' : ' (perfil)');
    }
    if ($eff['scope']) {
        $obs[] = 'escopo: ' . implode(', ', array_map(fn ($p) => $policies[$p] ?? ('#' . $p), $eff['scope']));
    }
    echo '<tr><td>' . Ui::avatar(Ui::userName($uid)) . ' ' . Ui::e(Ui::userName($uid)) . '</td><td class="small">' . Ui::e(implode(', ', $profNames) ?: 'sem perfil') . '</td><td class="small">';
    foreach ($where as [$pol, $pos, $role]) {
        echo Ui::e($pol . ' · N' . $pos) . ' ' . ($role === 'act' ? Ui::badge('escalado', '#22c55e') : Ui::badge('só notificado', '#6366f1')) . '<br>';
    }
    echo '</td>';
    foreach (['ack', 'resolve', 'silence', 'cancel', 'logs', 'slo', 'all'] as $cap) {
        echo '<td class="text-center">' . $cell($cap) . '</td>';
    }
    echo '<td class="small">' . Ui::e(implode('; ', $obs)) . '</td></tr>';
}
echo $people ? '' : "<tr><td colspan='11' class='text-center text-secondary'>Nenhuma hierarquia ativa com níveis.</td></tr>";
echo '</tbody></table></div><div class="card-footer small text-secondary">Calculado a partir do perfil, das exceções e do papel de cada pessoa. “Como escalado” = pode agir só nos escalonamentos em que foi acionado.</div></div>';
echo '</div>';
Menu::footer();
