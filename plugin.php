<?php

/**
 * osTicket plugin manifest: Ticket Automation
 *
 * Tested against osTicket 1.18.4. PluginManager splits the 'plugin' key with
 * explode(':'), see include/class.plugin.php.
 */

return array(
    'id'          => 'studio-prisma:ticket-automation',
    'version'     => '0.1.0',
    'ost_version' => '1.18',
    'name'        => 'Ticket Automation',
    'author'      => 'Studio Prisma',
    'url'         => 'https://github.com/studio-prisma/osticket-plugin-ticket-automation',
    'description' => 'Rules that change tickets on events: a customer or collaborator message, '
                   . 'an agent reply or note, or a reached due date. Each plugin instance is one '
                   . 'rule with trigger, conditions and actions. Every run leaves an internal note.',
    'plugin'      => 'class.TicketAutomationPlugin.php:TicketAutomationPlugin',
);
