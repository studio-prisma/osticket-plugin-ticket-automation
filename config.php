<?php

declare(strict_types=1);

/**
 * Settings of one rule. Every plugin instance is one rule:
 * Admin Panel, Manage, Plugins, Ticket Automation, Instances, Add New Instance.
 *
 * Choice lists come from TicketAutomationCatalog (plain SQL), because the
 * form is also built during plugin bootstrap.
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.forms.php';
require_once __DIR__ . '/src/TicketAutomationRule.php';
require_once __DIR__ . '/src/TicketAutomationCatalog.php';

class TicketAutomationConfig extends PluginConfig
{
    public function getOptions()
    {
        $multi = static function (string $prompt): array {
            return array('multiselect' => true, 'prompt' => $prompt);
        };

        return array(

            'sec_mode' => new SectionBreakField(array(
                'label' => 'Mode',
                'hint'  => 'Enable or disable this rule with the instance status above. '
                         . 'Instances run in the order they were created; per event only the first matching rule runs.',
            )),

            'dry_run' => new BooleanField(array(
                'label'         => 'Dry run',
                'default'       => false,
                'configuration' => array(
                    'desc' => 'Change nothing. Write what the rule would do to the system log '
                            . '(Admin Panel, Dashboard, System Logs) instead.',
                ),
            )),

            'sec_trigger' => new SectionBreakField(array(
                'label' => 'Trigger',
            )),

            'trig_user' => new BooleanField(array(
                'label'         => 'Message from the customer',
                'default'       => true,
                'configuration' => array('desc' => 'A new message whose sender is the ticket\'s user.'),
            )),

            'trig_collab' => new BooleanField(array(
                'label'         => 'Message from a collaborator',
                'default'       => false,
                'configuration' => array('desc' => 'A new message from anyone else on the thread (CC, collaborators).'),
            )),

            'trig_reply' => new BooleanField(array(
                'label'         => 'Agent reply',
                'default'       => false,
                'configuration' => array('desc' => 'A response an agent sends to the customer.'),
            )),

            'trig_note' => new BooleanField(array(
                'label'         => 'Internal note by an agent',
                'default'       => false,
                'configuration' => array('desc' => 'System notes, this plugin\'s own among them, never trigger.'),
            )),

            'trig_due' => new BooleanField(array(
                'label'         => 'Due date reached',
                'default'       => false,
                'configuration' => array(
                    'desc' => 'Checked on every cron run (cron job or auto-cron). Needs an action that '
                            . 'moves the ticket out of the condition, see below.',
                ),
            )),

            'due_source' => new ChoiceField(array(
                'label'   => 'Due date to check',
                'default' => 'manual',
                'choices' => array(
                    'manual'        => 'Due date set on the ticket only',
                    'manual_or_sla' => 'Due date, or the SLA due date if none is set',
                ),
            )),

            'sec_cond' => new SectionBreakField(array(
                'label' => 'Conditions',
                'hint'  => 'All of them must hold. Empty lists mean "any".',
            )),

            'cond_status' => new ChoiceField(array(
                'label'         => 'Current status',
                'required'      => true,
                'default'       => '',
                'choices'       => TicketAutomationCatalog::conditionChoices(),
                'hint'          => 'Required. Open statuses only.',
                'configuration' => $multi('Select statuses'),
            )),

            'cond_dept' => new ChoiceField(array(
                'label'         => 'Department',
                'default'       => '',
                'choices'       => TicketAutomationCatalog::departments(),
                'configuration' => $multi('Any department'),
            )),

            'cond_topic' => new ChoiceField(array(
                'label'         => 'Help topic',
                'default'       => '',
                'choices'       => TicketAutomationCatalog::topics(),
                'configuration' => $multi('Any help topic'),
            )),

            'cond_assigned' => new BooleanField(array(
                'label'         => 'Only assigned tickets',
                'default'       => false,
                'configuration' => array('desc' => 'Assigned to an agent or a team.'),
            )),

            'skip_auto' => new BooleanField(array(
                'label'         => 'Ignore bounces and auto-replies',
                'default'       => true,
                'configuration' => array(
                    'desc' => 'Out-of-office replies and delivery failures do not count as a message. '
                            . 'Detected from the mail headers, as osTicket does.',
                ),
            )),

            'sec_act' => new SectionBreakField(array(
                'label' => 'Actions',
                'hint'  => 'Every run writes an internal note. No mail goes to the customer.',
            )),

            'act_status' => new ChoiceField(array(
                'label'   => 'Set status',
                'default' => '0',
                'choices' => TicketAutomationCatalog::targetChoices(),
                'hint'    => 'Enabled statuses only. A closed status only with the due-date trigger.',
            )),

            'act_priority' => new ChoiceField(array(
                'label'   => 'Set priority',
                'default' => '0',
                'choices' => TicketAutomationCatalog::priorities(),
            )),

            'act_due' => new ChoiceField(array(
                'label'   => 'Due date',
                'default' => 'keep',
                'choices' => array(
                    'keep'  => 'Keep',
                    'clear' => 'Clear',
                    'set'   => 'Set to today plus the days below',
                ),
            )),

            'act_due_days' => new TextboxField(array(
                'label'         => 'Days',
                'default'       => '3',
                'hint'          => 'Used with "Set". 1 to ' . TicketAutomationRule::DUE_DAYS_MAX . '.',
                'configuration' => array('size' => 5, 'length' => 3, 'validator' => 'number'),
            )),

            'act_unanswered' => new BooleanField(array(
                'label'         => 'Mark unanswered',
                'default'       => false,
                'configuration' => array('desc' => 'osTicket does this itself on a customer message.'),
            )),

            'act_note' => new TextareaField(array(
                'label'         => 'Internal note',
                'default'       => TicketAutomationRule::DEFAULT_NOTE,
                'hint'          => 'Plain text. Placeholders: {rule} {trigger} {sender} {old_status} '
                                 . '{new_status} {ticket_number} {due_date} {changes}. Empty: the default text.',
                'configuration' => array('rows' => 3, 'cols' => 60, 'html' => false, 'length' => TicketAutomationRule::NOTE_MAX),
            )),
        );
    }

    /**
     * Same checks the runner repeats before every run, so a rule that would
     * be refused at run time can't be saved in the first place.
     */
    public function pre_save(&$config, &$errors)
    {
        $rule = TicketAutomationRule::normalise($config);
        foreach (TicketAutomationRule::validate($rule, TicketAutomationCatalog::statuses(true), $config) as $field => $msg) {
            $errors[$field] = $msg;
        }
        if (isset($errors['err'])) {
            return false;
        }
        if ($errors) {
            $errors['err'] = 'The rule was not saved. ' . implode(' ', array_values(array_filter(
                $errors, static function ($k) { return $k !== 'err'; }, ARRAY_FILTER_USE_KEY)));
        }
        return !$errors;
    }
}
