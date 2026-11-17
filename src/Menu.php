<?php

namespace GlpiPlugin\Escalations;

use Session;

/** Seção "Escalations" do menu lateral e endereços dos recursos do plugin. */
class Menu
{
    public const SECTOR = 'escalations';

    public static function url(string $path): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/escalations' . $path;
    }

    /** Endereço relativo, como o menu do GLPI espera. */
    public static function page(string $path): string
    {
        return '/plugins/escalations' . $path;
    }

    public static function css(): string
    {
        $file = dirname(__DIR__) . '/public/css/escalations.css';
        $v = PLUGIN_ESCALATIONS_VERSION . '-' . (is_file($file) ? filemtime($file) : 0);
        return "<link rel='stylesheet' href='" . htmlspecialchars(self::url('/css/escalations.css') . '?m=' . $v, ENT_QUOTES) . "'>";
    }

    /** Itens da seção conforme os direitos. */
    public static function content(): array
    {
        $items = [];
        if (Escalation::canView()) {
            $items['painel'] = ['title' => 'Painel', 'page' => self::page('/front/painel.php'), 'icon' => 'ti ti-radar-2'];
            $items['escalonamentos'] = ['title' => 'Escalonamentos', 'page' => self::page('/front/escalonamentos.php'), 'icon' => 'ti ti-urgent',
                'links' => Escalation::canCreate() ? ['add' => self::page('/front/escalonamento.php?novo=1'), 'search' => self::page('/front/escalonamentos.php')] : []];
            if (Perms::can('slo')) {
                $items['slo'] = ['title' => 'SLO e SLA', 'page' => self::page('/front/slo.php'), 'icon' => 'ti ti-gauge'];
                $items['gaps'] = ['title' => 'Análise de gaps', 'page' => self::page('/front/gaps.php'), 'icon' => 'ti ti-chart-histogram'];
            }
            if (Perms::can('logs')) {
                $items['outbox'] = ['title' => 'Mensagens enviadas', 'page' => self::page('/front/outbox.php'), 'icon' => 'ti ti-mailbox'];
                $items['logs'] = ['title' => 'Logs rastreáveis', 'page' => self::page('/front/logs.php'), 'icon' => 'ti ti-file-search'];
            }
            if (Escalation::canManage()) {
                $items['hierarquias'] = ['title' => 'Hierarquias', 'page' => self::page('/front/hierarquias.php'), 'icon' => 'ti ti-binary-tree-2',
                    'links' => ['add' => self::page('/front/hierarquia.php'), 'search' => self::page('/front/hierarquias.php')]];
                $items['permissoes'] = ['title' => 'Permissões', 'page' => self::page('/front/permissoes.php'), 'icon' => 'ti ti-shield-lock'];
                $items['config'] = ['title' => 'Configurações', 'page' => self::page('/front/config.php'), 'icon' => 'ti ti-settings'];
            }
        }
        return $items;
    }

    public static function header(string $title, string $item): void
    {
        \Html::header($title, '', self::SECTOR, $item);
        echo self::css();
        echo \Html::script(self::url('/js/drawer.js'), ['version' => PLUGIN_ESCALATIONS_VERSION . '-' . @filemtime(dirname(__DIR__) . '/public/js/drawer.js')], false);
    }

    /** Fecha a página: imprime as gavetas registradas e o rodapé do GLPI. */
    public static function footer(): void
    {
        echo Drawer::flush();
        \Html::footer();
    }

    /** Página protegida: exige login e o direito (e, se pedido, o de edição ou o de administração). */
    public static function guard(bool $edit = false, bool $manage = false, string $cap = ''): void
    {
        Session::checkLoginUser();
        if (!Escalation::canView() || ($edit && !Escalation::canUpdate()) || ($manage && !Escalation::canManage()) || ($cap !== '' && !Perms::can($cap))) {
            throw new \Glpi\Exception\Http\AccessDeniedHttpException();
        }
    }
}
