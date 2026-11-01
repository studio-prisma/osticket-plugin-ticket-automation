<?php

declare(strict_types=1);

/**
 * Ticket Automation for osTicket 1.18.x
 *
 * One plugin instance = one rule. osTicket bootstraps every enabled instance
 * with its own config side-loaded (PluginInstance::bootstrap()), so each call
 * of bootstrap() registers one rule; the signals are connected once.
 *
 * Bootstrap runs before osTicket's config and staff session are complete:
 * nothing here may call getLocalName(), look at the signed-in agent or touch
 * a ticket. It only reads the instance settings and connects signals. All
 * work happens in TicketAutomationRunner, inside the signal handlers.
 *
 * Kill switch without the admin panel: a file named DISABLED in the plugin
 * folder stops every rule on the next request.
 *
 * @see docs/INSTALL.md
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.signal.php';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/TicketAutomationRule.php';
require_once __DIR__ . '/src/TicketAutomationCatalog.php';
require_once __DIR__ . '/src/TicketAutomationMatcher.php';
require_once __DIR__ . '/src/TicketAutomationRunner.php';

class TicketAutomationPlugin extends Plugin
{
    public const VERSION = '0.1.0';

    var $config_class = 'TicketAutomationConfig';

    /** @var bool */
    private static $connected = false;

    /** Every instance is a rule. */
    function isMultiInstance()
    {
        return true;
    }

    public static function killSwitchActive(): bool
    {
        return is_file(__DIR__ . '/DISABLED');
    }

    public function bootstrap(): void
    {
        if (self::killSwitchActive()) {
            return;
        }
        $config = $this->getConfig();
        if (!$config) {
            return;
        }
        $instance = method_exists($config, 'getInstance') ? $config->getInstance() : null;
        $id = $instance ? (int) $instance->getId() : 0;
        if ($id <= 0) {
            return;
        }

        $raw = array();
        foreach (TicketAutomationRule::CONFIG_KEYS as $key) {
            $raw[$key] = $config->get($key);
        }
        TicketAutomationRunner::register(TicketAutomationRule::normalise(
            $raw, $id, html_entity_decode((string) $instance->getName(), ENT_QUOTES, 'UTF-8')));

        if (!self::$connected) {
            self::$connected = true;
            Signal::connect('threadentry.created', array('TicketAutomationRunner', 'onThreadEntry'));
            Signal::connect('cron', array('TicketAutomationRunner', 'onCron'));
        }
    }
}
