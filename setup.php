<?php

/**
 * Plugin "Escalations" para GLPI 11 e 12.
 *
 * Escalonamento de áreas por chamados (SLA), eventos (webhook do Zabbix e outros), com hierarquia de níveis,
 * avisos por e-mail (com histórico das escaladas), pop-up no GLPI, Teams e WhatsApp, ACK que interrompe a cadeia,
 * logs rastreáveis (encadeados por hash) e monitoramento de SLO/SLA.
 */

use Glpi\Http\Firewall;
use Glpi\Http\SessionManager;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Escalations\Escalation;
use GlpiPlugin\Escalations\Menu;

define('PLUGIN_ESCALATIONS_VERSION', '0.4.0');
define('PLUGIN_ESCALATIONS_MIN_GLPI', '11.0.0');
define('PLUGIN_ESCALATIONS_MAX_GLPI', '12.0.99');

function plugin_init_escalations(): void
{
    global $PLUGIN_HOOKS;

    // aba do plugin em Administração → Perfis
    Plugin::registerClass(GlpiPlugin\Escalations\ProfileTab::class, ['addtabon' => [Profile::class]]);
    Plugin::registerClass(Escalation::class);

    // webhook de eventos e resposta do WhatsApp chamam sem sessão (a autenticação é o token / a assinatura HMAC);
    // pending.php responde 401 em JSON em vez de redirecionar ao login (ele mesmo confere sessão e direito)
    if (class_exists(Firewall::class) && method_exists(Firewall::class, 'addPluginStrategyForLegacyScripts')) {
        Firewall::addPluginStrategyForLegacyScripts('escalations', '#^/front/(webhook|inbound|pending)\.php$#', Firewall::STRATEGY_NO_CHECK);
    }
    // sem sessão não há token CSRF: o GLPI 11 recusaria o POST de fora
    if (class_exists(SessionManager::class) && method_exists(SessionManager::class, 'registerPluginStatelessPath')) {
        SessionManager::registerPluginStatelessPath('escalations', '#^/front/(webhook|inbound)\.php$#');
    }

    // sessões abertas antes da instalação ainda não têm o direito do plugin
    if (isset($_SESSION['glpiactiveprofile']['id']) && !isset($_SESSION['glpiactiveprofile'][Escalation::$rightname])) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Escalation::$rightname]);
        $_SESSION['glpiactiveprofile'][Escalation::$rightname] = (int) ($rights[Escalation::$rightname] ?? 0);
    }

    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['escalations']     = [Menu::SECTOR => [Escalation::class]];
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['escalations'] = 'plugin_escalations_redefine_menus';
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['escalations']        = ['css/escalations.css'];
    // pop-up de escalonamento (consulta front/pending.php) e recolher grupos das tabelas
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['escalations'] = ['js/escalations.js'];
}

/** Seção "Escalations" no menu lateral, depois do Customer 360 (ou do CRM, RH, Financeiro...). */
function plugin_escalations_redefine_menus(array $menu): array
{
    $content = Menu::content();
    unset($menu[Menu::SECTOR]);
    if (!$content) {
        return $menu;
    }
    $section = [Menu::SECTOR => [
        'title'   => 'Escalations',
        'icon'    => 'ti ti-urgent',
        'types'   => [Escalation::class],
        'content' => $content,
        'default' => reset($content)['page'],
    ]];
    foreach (['customer360', 'crm', 'rh', 'financeiro', 'pmo', 'dashboard'] as $after) {
        if (isset($menu[$after])) {
            $out = [];
            foreach ($menu as $key => $value) {
                $out[$key] = $value;
                if ($key === $after) {
                    $out += $section;
                }
            }
            return $out;
        }
    }
    return $section + $menu;
}

function plugin_version_escalations(): array
{
    return [
        'name'         => 'Escalations',
        'version'      => PLUGIN_ESCALATIONS_VERSION,
        'author'       => 'Evandro Evangelista',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_ESCALATIONS_MIN_GLPI, 'max' => PLUGIN_ESCALATIONS_MAX_GLPI],
            'php'  => ['min' => '8.2'],
        ],
    ];
}

function plugin_escalations_check_prerequisites(): bool
{
    return true;
}

function plugin_escalations_check_config($verbose = false): bool
{
    return true;
}
