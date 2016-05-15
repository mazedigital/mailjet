<?php
/**
 * @package email-gateways
 */

require_once EXTENSIONS . '/mailjet/vendor/autoload.php';

use \Mailjet\Resources;

/**
 * One of the two core email gateways.
 * Provides simple SMTP functionalities.
 * Supports AUTH LOGIN, SSL and TLS.
 *
 * @author Huib Keemink, Michael Eichelsdoerfer
 */
class MailjetGateway extends EmailGateway
{
    protected $_bulk = false;
    protected $_messages = array();
    protected $_envelope_from;

    /**
     * Sets the bulk status of email; if bulk is true emails will be sent in groups of 50 or on connection close
     * Particularly useful for ENM
     *
     * @param boolean $bulk
     */
    public function setBulk($bulk)
    {
        $this->_bulk = $bulk;
    }


    /**
     * Returns the name, used in the dropdown menu in the preferences pane.
     *
     * @return array
     */
    public static function about()
    {
        return array(
            'name' => __('MailJet'),
        );
    }

    /**
     * Constructor. Sets basic default values based on preferences.
     *
     * @throws EmailValidationException
     */
    public function __construct()
    {
        parent::__construct();
        $this->setConfiguration(Symphony::Configuration()->get('mailjet'));
    }

    /**
     * Send an email using an SMTP server
     *
     * @throws EmailGatewayException
     * @throws EmailValidationException
     * @throws Exception
     * @return boolean
     */
    public function send()
    {
        $this->validate();

        $recipients = array();

        foreach ($this->_recipients as $name => $email) {
            $recipients[] = array(
                    'Name' => $name,
                    'Email' => $email,
                );
        }

        $body = [
            // 'FromEmail' => "pilot@mailjet.com",
            // 'FromEmail' => $this->_reply_to_email_address,
            'FromEmail' => $this->_sender_email_address,
            'FromName' => $this->_sender_name,
            'Subject' => $this->_subject,
            'Text-part' => $this->_text_plain,
            'Html-part' => $this->_text_html,
            'Recipients' => $recipients,
            'Headers' => []
        ];

        //Attachments
        //Inline_attachments
        //Mj-campaign

        // var_dump($body);die;

        // Build the 'Reply-To' header field body
        // headers need to be a json object
        if (!empty($this->_reply_to_email_address)) {
            $reply_to = empty($this->_reply_to_name)
                        ? $this->_reply_to_email_address
                        : $this->_reply_to_name . ' <'.$this->_reply_to_email_address.'>';

            $body["Headers"]["Reply-To"] = $reply_to;
        }

        if (empty($body["Headers"])){
            unset($body["Headers"]);
        } else {
            $body["Headers"] = json_encode($body["Headers"]);
        }


        try {
            $driver = ExtensionManager::getInstance('Mailjet');

            if ($this->_bulk){
                $driver->addBulkMessage($body);
                // $this->_messages[] = $body;
                // if (sizeof($this->_messages == 50)){
                //     $this->sendBulk;
                // }
            } else {

                // $response = $driver->getLists();
                $response = $driver->send(['body' => $body]);
                // var_dump($response->success());die;

                $this->reset();
            }
        } catch (Exception $e) {
            throw new EmailGatewayException($e->getMessage());
        }

        return true;
    }

    public function closeConnection(){
        try {
            $driver = ExtensionManager::getInstance('Mailjet');
            $driver->triggerBulkSend();
        } catch (Exception $e) {
            throw new EmailGatewayException($e->getMessage());
        }

    }

    /**
     * Resets the headers, body, subject
     *
     * @return void
     */
    public function reset()
    {
        $this->_header_fields = array();
        $this->_envelope_from = null;
        $this->_recipients = array();
        $this->_subject = null;
        $this->_body = null;
        $this->_messages = array();
    }

    /**
     * Sets all configuration entries from an array.
     *
     * @param array $config
     *  All configuration entries stored in a single array.
     *  The array should have the format of the $_POST array created by the preferences HTML.
     * @throws EmailValidationException
     * @since 2.3.1
     * @return void
     */
    public function setConfiguration($config)
    {
        $this->setFrom($config['from-email'], $config['from-name']);
    }

}
