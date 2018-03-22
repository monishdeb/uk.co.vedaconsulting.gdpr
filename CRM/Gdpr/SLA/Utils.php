<?php

require_once 'CRM/Core/Page.php';

class CRM_Gdpr_SLA_Utils {

  static protected $activityTypeName = 'SLA Acceptance';

  static protected $customGroupName = 'SLA_Acceptance';

  static protected $customFieldNameTC = 'Terms_Conditions';
  
  /**
   * @var key used in session to flag that the acceptance form should be
   * displayed.
   */
  static protected $promptFlagSessionKey = 'Gdpr_SLA_do_prompt';

  /**
   * Getter for promptFlagSessionkey.
   */
  static function getPromptFlagSessionKey() {
    return self::$promptFlagSessionKey;
  }

  /**
   * Sets a flag in the user session to show the form
   * on the next request.
   */
  static function flagShowForm() {
    $session = CRM_Core_Session::singleton();
    $session->set(self::getPromptFlagSessionKey(), 1);
  }

  /**
   * Sets flag in the user session to not show modal form.
   */
  static function unflagShowForm() {
    $session = CRM_Core_Session::singleton();
    $session->set(self::getPromptFlagSessionKey(), -1);
  }
  
  /**
   * Determines whether to show modal form.
   */
  static function showFormIsFlagged() {
    $session = CRM_Core_Session::singleton();
    return $session->get(self::getPromptFlagSessionKey()) == 1;
  }

  /**
   * Determines whether the form has been submitted and should not
   * be shown again.
   */
  static function showFormIsUnflagged() {
    $session = CRM_Core_Session::singleton();
    return $session->get(self::getPromptFlagSessionKey()) == -1;
  }

  /**
   * Displays modal acceptance form via CiviCRM.
   */
  static function showForm() {
		$formPath = '/civicrm/sla/accept';
    $currPath = $_SERVER['REQUEST_URI'];
    if (FALSE !== strpos($currPath, $formPath)) {
      return;
    }
    $script = <<< EOT
if (typeof CRM == 'object') {
   CRM.loadForm("$formPath")
  // Attach an event handler
  .on('crmFormSuccess', function(event, data) {
  }); 
}
EOT;
	  CRM_Core_Resources::singleton()->addScript($script);
  }

  /**
   * Gets extension settings.
   *
   * @return array
   */
  static function getSettings() {
    static $settings = array();
    if (!$settings) {
      $settings = CRM_Gdpr_Utils::getGDPRSettings();
    }
    return $settings;
  }

  /**
   * Determines whether this extension should check and prompt the user
   * to accept Terms and Conditions. Alternatively the CMS may implement
   * the acceptance form instead.
   *
   * @return bool
   */
  static function isPromptForAcceptance() {
    $settings = self::getSettings();
    return $settings['sla_prompt'] == 1;
  }

  /**
   * Gets the last SLA Acceptance activity for a contact.
   */
  static function getContactLastAcceptance($contactId) {
    $result = civicrm_api3('Activity', 'get', array(
      'sequential' => 1,
      'activity_type_id' => self::$activityTypeName,
      'target_contact_id' => $contactId,
      'options' => array(
        'sort' => 'activity_date_time asc',
        'limit' => 1,
      ),
    ));
    if (!empty($result['values'])) {
      return $result['values'][0];
    }
  }

  /**
   * Records a contact accepting Terms and Conditions.
   */
  static function recordSLAAcceptance($contactId = NULL) {
    $settings = self::getSettings();
    $contactId = $contactId ? $contactId : CRM_Core_Session::singleton()->getLoggedInContactID();
    if (!$contactId) {
      return;
    }
    $termsConditionsUrl = $settings['sla_tc'];
    $termsConditionsField = self::getTermsConditionsField();
    $termsConditionsFieldKey = 'custom_' . $termsConditionsField['id'];
    $params = array(
      'source_contact_id' => $contactId,
      'target_id' => $contactId,
      'subject' => 'Terms and Conditions accepted',
      'status_id' => 'Completed',
      'activity_type_id' => self::$activityTypeName,
      'custom_' . $termsConditionsField['id'] => $termsConditionsUrl,
    );
    $result = civicrm_api3('Activity', 'create', $params);
  }

  static function isContactDueAcceptance($contactId = NULL) {
	  $contactId = $contactId ? $contactId : CRM_Core_Session::singleton()->getLoggedInContactID();
    if (!$contactId) {
      return;
    }
    $settings = self::getSettings();
    $lastAcceptance = self::getContactLastAcceptance($contactId);
    if (!$lastAcceptance) {
      // No acceptance, so due for one.
      return TRUE;
    }
    $months = !empty($settings['sla_period']) ? $settings['sla_period'] : 12;
    $seconds_in_year = 365 * 24 * 60 * 60;
    $acceptancePeriod = (($months / 12) * $seconds_in_year);
    $acceptanceDate = strtotime($lastAcceptance['activity_date_time']);
    $acceptanceDue = $acceptanceDate + $acceptancePeriod;
    return $acceptanceDue < time();
  }

  /**
   * Gets the Url to the current Terms & Conditions file.
   *
   * @param bool $absolute
   *  Whether to include the base url of the site.
   *
   *  @return string
   **/
  static function getTermsConditionsUrl($absolute = FALSE) {
    $url = '';
    $settings = CRM_Gdpr_Utils::getGDPRSettings();
    if (!empty($settings['sla_tc'])) {
      $url = $settings['sla_tc'];
      if (!$absolute) {
        return $url;
      }
      if (0 == strpos($url, '/')) {
        $url = substr($url, 1);
      }
      return  CRM_System::url($url);
    }
  }

  /**
   * Gets the Link label for Terms & Conditions file.
   *
   *  @return string
   **/
  static function getLinkLabel() {
    return self::getSetting('sla_link_label', 'Terms &amp; Conditions');
  }

  /**
   * Gets the checkbox text Terms & Conditions agreement.
   *
   *  @return string
   */
  static function getCheckboxText() {
    return self::getSetting('sla_checkbox_text', 'I accept the Terms &amp; Conditions.');
  }

  private static function getSetting($name, $default = NULL) {
    $val = '';
    $settings = CRM_Gdpr_Utils::getGDPRSettings();
    if (!empty($settings[$name])) {
      $val = $settings[$name];
    }
    return $val ? $val : $default;

  }

  /**
   * Gets a custom field definition by name and group name.
   *
   * @param string $fieldName
   * @param string $groupName
   *
   * @return array
   */
  static function getCustomField($fieldName, $groupName) {
    if (!$fieldName || !$groupName) {
      return;
    }
    $result = civicrm_api3('CustomGroup', 'get', array(
  	  'sequential' => 1,
      'name' => $groupName,
      'api.CustomField.get' => array(
        'custom_group_id' => "\$value.id",
        'name' => $fieldName
      ),
    ));
    if (!empty($result['values'][0]['api.CustomField.get']['values'])) {
      return $result['values'][0]['api.CustomField.get']['values'][0];
    }
  }

  /**
   * Get definition for the field holding Terms and Conditions.
   */
  static function getTermsConditionsField() {
    static $field = array();
    if (!$field) {
      $field = self::getCustomField('Terms_Conditions', 'SLA_Acceptance');
    }
    return $field;
  }

}//End Class
