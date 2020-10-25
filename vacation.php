<?php
/*
 * Vacation plugin that adds a new tab to the settings section
 * to enable forward / out of office replies.
 *
 * @package	plugins
 * @uses	rcube_plugin
 * @author	Jasper Slits <jaspersl@gmail.com>
 * @version	1.9
 * @license     GPL
 * @link	https://sourceforge.net/projects/rcubevacation/
 * @todo	See README.TXT
*/

// Load required dependencies
require 'lib/vacationdriver.class.php';
require 'lib/dotforward.class.php';
require 'lib/vacationfactory.class.php';
require 'lib/VacationConfig.class.php';

class vacation extends rcube_plugin {

    public $task = 'settings';
    private $v = "";
    private $inicfg = "";
    private $enableVacationTab = true;
    private $vcObject;

    public function init() {
        $this->add_texts('localization/', array('vacation'));
        $this->load_config();
        
        $this->inicfg = $this->readIniConfig();

        

        // Don't proceed if the current host does not support vacation
        if (!$this->enableVacationTab) {
            return false;
        }

        $this->v = VacationDriverFactory::Create($this->inicfg['driver']);

        $this->v->setIniConfig($this->inicfg);
        $this->add_hook('settings_actions', array($this, 'settings_actions'));
        $this->register_action('plugin.vacation', array($this, 'vacation_init'));
        $this->register_action('plugin.vacation-save', array($this, 'vacation_save'));
        $this->register_handler('plugin.vacation_form', array($this, 'vacation_form'));
        // The vacation_aliases method is defined in vacationdriver.class.php so use $this->v here
        $this->register_action('plugin.vacation_aliases', array($this->v, 'vacation_aliases'));
        $this->include_script('vacation.js');
        $this->include_stylesheet('skins/default/vacation.css');
        $this->rcmail = rcmail::get_instance();
        $this->user = $this->rcmail->user;
        $this->identity = $this->user->get_identity();
        
        // forward settings are shared by ftp,sshftp and setuid driver.
        $this->v->setDotForwardConfig($this->inicfg['driver'],$this->vcObject->getDotForwardCfg());
    }
    
    function settings_actions($args)
    {
        // register as settings action
        $args['actions'][] = array(
            'action' => 'plugin.vacation',
            'class'  => 'vacation',
            'label'  => 'vacation',
            'title'  => 'vacation',
            'domain' => 'vacation',
        );

        return $args;
    }

    public function vacation_init() {
        $this->add_texts('localization/', array('vacation'));
        $rcmail = rcmail::get_instance();
        $rcmail->output->set_pagetitle($this->gettext('autoresponder'));
        //Load template
        $rcmail->output->send('vacation.vacation');
    }
    
    public function vacation_save() {
        $rcmail = rcmail::get_instance();

        // Initialize the driver
        $this->v->init();
        
        if ($this->v->save()) {
//         $this->v->getActionText();// Dummy for now
            $rcmail->output->show_message($this->gettext("success_changed"), 'confirmation');
        } else {
            $rcmail->output->show_message($this->gettext("failed"), 'error');
        }
        $this->vacation_init();
    }

    // Parse config.ini and get configuration for current host
    private function readIniConfig() {
        $this->vcObject = new VacationConfig();
        $this->vcObject->setCurrentHost($_SESSION['imap_host']);
        $config = $this->vcObject->getCurrentConfig();

        if (false !== ($errorStr = $this->vcObject->hasError())) {
            rcube::raise_error(array('code' => 601, 'type' => 'php', 'file' => __FILE__,
                        'message' => sprintf("Vacation plugin: %s", $errorStr)), true, true);
        }
        $this->enableVacationTab = $this->vcObject->hasVacationEnabled();

        return $config;
    }
    
    public function vacation_form() {
        $rcmail = rcmail::get_instance();
        // Initialize the driver
        $this->v->init();
        $settings = $this->v->_get();
        
        // Load default body & subject if present.
        if (empty($settings['subject']) && $defaults = $this->v->loadDefaults()) {
            $settings['subject'] = $defaults['subject'];
            $settings['body'] = $defaults['body'];
        }

	    $table = new html_table(array('cols' => 2,'class' => 'propform cols-sm-6-6'));

        $table->set_row_attribs(array('class' => 'form-group row'));
        $table->add('title col-form-label', html::label(array('for' => 'vacation_form', 'class' => 'col-form-label'), $this->gettext('outofoffice')));
        $table->add('col-sm-6', null);
    
        // Auto-reply enabled
        if (!isset($this->inicfg['disable_enabled']) || (isset($this->inicfg['disable_enabled']) && $this->inicfg['disable_enabled']==false)) {
			$field_id = 'vacation_enabled';
			$input_autoresponderactive = new html_checkbox(array('name' => '_vacation_enabled', 'id' => $field_id, 'value' => 1));
			$table->set_row_attribs(array('class' => 'form-group row'));
			$table->add('title col-sm-6', html::label(array('for' => 'vacation_enabled', 'class' => 'col-form-label'), $this->gettext('autoreply')));
			$table->add('col-sm-6', $input_autoresponderactive->show($settings['enabled']));
		}

        // Interval (if cpanel)
        if ($this->inicfg['driver']=='cpanel') {
			$field_id = 'vacation_interval';
			$input_autoresponderinterval = new html_inputfield(array('name' => '_vacation_interval', 'id' => $field_id, 'value' => '', 'placeholder'=>'eg; 1'));
			$table->set_row_attribs(array('class' => 'form-group row'));
			$table->add('title col-sm-6', html::label(array('for' => 'vacation_interval', 'class' => 'col-form-label'), $this->gettext('interval')));
			$table->add('col-sm-6', $input_autoresponderinterval->show($settings['interval']));
			
			$field_id = 'vacation_start';
			$input_autoresponderstart = new html_inputfield(array('name' => '_vacation_start', 'id' => $field_id, 'value' => '', 'placeholder'=>'eg; '.date('Y-m-d 17:00', strtotime('today'))));
			$table->set_row_attribs(array('class' => 'form-group row'));
			$table->add('title col-sm-6', html::label(array('for' => 'vacation_start', 'class' => 'col-form-label'), $this->gettext('start')));
			$table->add('col-sm-6', $input_autoresponderstart->show($settings['start']));			
						
			$field_id = 'vacation_stop';
			$input_autoresponderstop = new html_inputfield(array('name' => '_vacation_stop', 'id' => $field_id, 'value' => '', 'placeholder'=>'eg; '.date('Y-m-d 17:00', strtotime('today +7 days'))));
			$table->set_row_attribs(array('class' => 'form-group row'));
			$table->add('title col-sm-6', html::label(array('for' => 'vacation_stop', 'class' => 'col-form-label'), $this->gettext('stop')));
			$table->add('col-sm-6', $input_autoresponderstop->show($settings['stop']));						
			
			$field_id = 'vacation_html';
			$input_autoresponderhtml = new html_checkbox(array('name' => '_vacation_html', 'id' => $field_id, 'value' => 1));
			$table->set_row_attribs(array('class' => 'form-group row'));
			$table->add('title col-sm-6', html::label(array('for' => 'vacation_html', 'class' => 'col-form-label'), $this->gettext('html')));
			$table->add('col-sm-6', $input_autoresponderhtml->show($settings['html']));
		}
		
        // Subject
        $field_id = 'vacation_subject';
        $input_autorespondersubject = new html_inputfield(array('name' => '_vacation_subject', 'id' => $field_id, 'class' => 'form-control', 'size' => 50));
        $table->set_row_attribs(array('class' => 'form-group row'));
        $table->add('title col-sm-6', html::label(array('for' => 'vacation_subject', 'class' => 'col-form-label'), $this->gettext('subject')));
        $table->add('col-sm-6', $input_autorespondersubject->show($settings['subject']));

        // Out of office body
        $field_id = 'vacation_body';
        $input_autoresponderbody = new html_textarea(array('name' => '_vacation_body', 'id' => $field_id, 'class' => 'form-control', 'cols' => 60, 'rows' => 16));
        $table->set_row_attribs(array('class' => 'form-group row'));
        $table->add('title col-sm-6', html::label(array('for' => 'vacation_body', 'class' => 'col-form-label'), $this->gettext('body')));
        $table->add('col-sm-6', $input_autoresponderbody->show($settings['body']));

        $out .= html::tag('fieldset', $class, html::tag('legend', null, $this->gettext('vacation')) . $table->show());

        /* We only use aliases for .forward and only if it's enabled in the config*/
        if ($this->v->useAliases()) {
            $size = 0;

            // If there are no multiple identities, hide the button and add increase the size of the textfield
            $hasMultipleIdentities = $this->v->vacation_aliases('buttoncheck');
            if ($hasMultipleIdentities == '') $size = 15;

            $field_id = 'vacation_aliases';
            $input_autoresponderalias = new html_inputfield(array('name' => '_vacation_aliases', 'id' => $field_id, 'class' => 'form-control', 'size' => 75+$size));
            $table->set_row_attribs(array('class' => 'form-group row'));
            $table->add(null, $input_autoresponderalias->show($settings['separate_alias']));
            $table->add(null, null);
            $table->set_row_attribs(array('class' => 'form-group row'));
            $table->add('title', html::label(array('for' => 'vacation_aliases', 'class' => 'col-form-label'), $this->gettext('aliases')));
            $table->add(null, $input_autoresponderalias->show($settings['alias']));

            // Inputfield with button
            if ($hasMultipleIdentities!='') {
                $aliases = new html_inputfield(array(
                    'type'    => 'button',
                    'href'    => '#',
                    'class' => 'button',
                    'label'      => $this->gettext['aliasesbutton'],
                    'title'      => $this->gettext['aliasesbutton'],
                ));

            }   
            $out .= html::div(array(
                'class'           => 'button',
            ), "$aliases");
        }
		
        $table = new html_table(array('cols' => 2,'class' => 'propform cols-sm-6-6'));
        // Keep a local copy of the mail
        if (!isset($this->inicfg['disable_keepcopy']) || (isset($this->inicfg['disable_keepcopy']) && $this->inicfg['disable_keepcopy']==false)) {
			$field_id = 'vacation_keepcopy';
			$input_localcopy = new html_checkbox(array('name' => '_vacation_keepcopy', 'id' => $field_id, 'value' => 1));
			$table->set_row_attribs(array('class' => 'form-group row'));
			$table->add('title col-sm-6', html::label(array('for' => 'vacation_keepcopy', 'class' => 'col-form-label'), $this->gettext('keepcopy')));
			$table->add('col-sm-6', $input_localcopy->show($settings['keepcopy']));
		}
	
        // Information on the forward in a seperate fieldset.
        if (!isset($this->inicfg['disable_forward']) || (isset($this->inicfg['disable_forward']) && $this->inicfg['disable_forward']==false)) {
			$table->set_row_attribs(array('class' => 'form-group row'));

            $table->add('title col-sm-6', html::label('separate_forward', $this->gettext('separate_forward')));
            $table->add('col-sm-6', null);

            // Forward mail to another account
            $field_id = 'vacation_forward';
            $input_autoresponderforward = new html_inputfield(array('name' => '_vacation_forward', 'id' => $field_id, 'class' => 'form-control', 'size' => 50));
            $table->set_row_attribs(array('class' => 'form-group row'));
            $table->add('title col-sm-6', html::label(array('for' => 'vacation_forward', 'class' => 'col-form-label'), $this->gettext('forwardingaddresses')));
            $table->add('col-sm-6', $input_autoresponderforward->show($settings['forward']));
        }
        $out .= $table->size() ? html::tag('fieldset', $class, html::tag('legend', null, $this->gettext('forward')) . $table->show()) : "";
        $rcmail->output->add_gui_object('vacationform', 'vacation-form');


        return $out;
    }
}

?>
