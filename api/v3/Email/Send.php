<?php

/**
 * Email.Send API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRM/API+Architecture+Standards
 */
function _civicrm_api3_email_send_spec(&$spec) {
  $spec['contact_id'] = array(
    'title' => 'Contact ID',
    'api.required' => 1,
  );
  $spec['template_id'] = array(
    'title' => 'Template ID',
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => 1,
  );
  $spec['case_id'] = array(
    'title' => 'Case ID',
    'type' => CRM_Utils_Type::T_INT,
  );
  $spec['contribution_id'] = array(
    'title' => 'Contribution ID',
    'type' => CRM_Utils_Type::T_INT,
  );
  $spec['alternative_receiver_address'] = array(
    'title' => 'Alternative receiver address',
    'type' => CRM_Utils_Type::T_STRING,
  );
  $spec['cc'] = array(
    'title' => 'Cc',
    'type' => CRM_Utils_Type::T_STRING,
  );
  $spec['bcc'] = array(
    'title' => 'Bcc',
    'type' => CRM_Utils_Type::T_STRING,
  );
}

/**
 * Email.Send API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_email_send($params) {
  $version = CRM_Core_BAO_Domain::version();
  if (!preg_match('/[0-9]+(,[0-9]+)*/i', $params['contact_id'])) {
    throw new API_Exception('Parameter contact_id must be a unique id or a list of ids separated by comma');
  }
  $alternativeEmailAddress = !empty($params['alternative_receiver_address']) ? $params['alternative_receiver_address'] : FALSE;

  $case_id = FALSE;
  if (isset($params['case_id'])) {
    $case_id = $params['case_id'];
  }

  // Compatibility with CiviCRM > 4.3
  if ($version >= 4.4) {
    $messageTemplates = new CRM_Core_DAO_MessageTemplate();
  }
  else {
    $messageTemplates = new CRM_Core_DAO_MessageTemplates();
  }
  $messageTemplates->id = $params['template_id'];

  $from = CRM_Core_BAO_Domain::getNameAndEmail();
  $from = "$from[0] <$from[1]>";
  if (isset($params['from_email']) && isset($params['from_name'])) {
    $from = $params['from_name'] . "<" . $params['from_email'] . ">";
  }
  elseif (isset($params['from_email']) || isset($params['from_name'])) {
    throw new API_Exception('You have to provide both from_name and from_email');
  }

  if (!$messageTemplates->find(TRUE)) {
    throw new API_Exception('Could not find template with ID: ' . $params['template_id']);
  }

  $tokenProc = _civicrm_api3_email_send_createTokenProcessor($params, $messageTemplates);
  $tokenProc->evaluate();

  $returnValues = array();
  foreach ($tokenProc->getRows() as $tokenRow) {
    /** @var \Civi\Token\TokenRow $tokenRow */
    $contactId = $tokenRow->context['contactId'];
    $messageSubject = $tokenRow->render('subject');
    $html = $tokenRow->render('body_html');
    $text = $tokenRow->render('body_text');

    list($contact) = CRM_Contact_BAO_Query::apiQuery([['contact_id', '=', $contactId, 0, 0]]);
    $contact = reset($contact); // CRM-4524 - Huh?

    if (!$contact || is_a($contact, 'CRM_Core_Error')) {
      throw new API_Exception('Could not find contact with ID: ' . $contactId);
    }

    if ($alternativeEmailAddress) {
      /**
       * If an alternative reciepient address is given
       * then send e-mail to that address rather than to
       * the e-mail address of the contact
       *
       */
      $toName = '';
      $email = $alternativeEmailAddress;
    }
    elseif ($contact['do_not_email'] || empty($contact['email']) || CRM_Utils_Array::value('is_deceased', $contact) || $contact['on_hold']) {
      /**
       * Contact is decaused or has opted out from mailings so do not send the e-mail
       */
      continue;
    }
    else {
      /**
       * Send e-mail to the contact
       */
      $email = $contact['email'];
      $toName = $contact['display_name'];
    }

    // set up the parameters for CRM_Utils_Mail::send
    $mailParams = array(
      'groupName' => 'E-mail from API',
      'from' => $from,
      'toName' => $toName,
      'toEmail' => $email,
      'subject' => $messageSubject,
      'messageTemplateID' => $messageTemplates->id,
    );

    if (!$html || $contact['preferred_mail_format'] == 'Text' || $contact['preferred_mail_format'] == 'Both') {
      // render the &amp; entities in text mode, so that the links work
      $mailParams['text'] = str_replace('&amp;', '&', $text);
    }
    if ($html && ($contact['preferred_mail_format'] == 'HTML' || $contact['preferred_mail_format'] == 'Both')) {
      $mailParams['html'] = $html;
    }
    if (isset($params['cc']) && !empty($params['cc'])) {
      $mailParams['cc'] = $params['cc'];
    }
    if (isset($params['bcc']) && !empty($params['bcc'])) {
      $mailParams['bcc'] = $params['bcc'];
    }
    $result = CRM_Utils_Mail::send($mailParams);
    if (!$result) {
      throw new API_Exception('Error sending e-mail to ' . $contact['display_name'] . ' <' . $email . '> ');
    }

    //create activity for sending e-mail.
    $activityTypeID = CRM_Core_OptionGroup::getValue('activity_type', 'Email', 'name');

    // CRM-6265: save both text and HTML parts in details (if present)
    if ($html and $text) {
      $details = "-ALTERNATIVE ITEM 0-\n$html\n-ALTERNATIVE ITEM 1-\n$text\n-ALTERNATIVE END-\n";
    }
    else {
      $details = $html ? $html : $text;
    }

    $activityParams = array(
      'source_contact_id' => $contactId,
      'activity_type_id' => $activityTypeID,
      'activity_date_time' => date('YmdHis'),
      'subject' => $messageSubject,
      'details' => $details,
      // FIXME: check for name Completed and get ID from that lookup
      'status_id' => 2,
    );

    $activity = CRM_Activity_BAO_Activity::create($activityParams);

    // Compatibility with CiviCRM >= 4.4
    if ($version >= 4.4) {
      $activityContacts = CRM_Core_OptionGroup::values('activity_contacts', FALSE, FALSE, FALSE, NULL, 'name');
      $targetID = CRM_Utils_Array::key('Activity Targets', $activityContacts);

      $activityTargetParams = array(
        'activity_id' => $activity->id,
        'contact_id' => $contactId,
        'record_type_id' => $targetID,
      );
      CRM_Activity_BAO_ActivityContact::create($activityTargetParams);
    }
    else {
      $activityTargetParams = array(
        'activity_id' => $activity->id,
        'target_contact_id' => $contactId,
      );
      CRM_Activity_BAO_Activity::createActivityTarget($activityTargetParams);
    }

    if (!empty($case_id)) {
      $caseActivity = array(
        'activity_id' => $activity->id,
        'case_id' => $case_id,
      );
      CRM_Case_BAO_Case::processCaseActivity($caseActivity);
    }

    $returnValues[$contactId] = array(
      'contact_id' => $contactId,
      'send' => 1,
      'status_msg' => 'Succesfully send e-mail to ' . ' <' . $email . '> ',
    );
  }

  return civicrm_api3_create_success($returnValues, $params, 'Email', 'Send');
  //throw new API_Exception(/*errorMessage*/ 'Everyone knows that the magicword is "sesame"', /*errorCode*/ 1234);
}

/**
 * Create an instance of the TokenProcessor. Populate it with
 * - Message templates (from $messageTemplate).
 * - Basic contextual data about each planned message (eg contact ID from $params).
 *
 * @param array $params
 * @param CRM_Core_DAO_MessageTemplate $messageTemplate
 * @return \Civi\Token\TokenProcessor
 */
function _civicrm_api3_email_send_createTokenProcessor($params, $messageTemplate) {
  // TODO: In discussion between aydun+totten, we wanted add a general item called 'schema'
  //   so that we could foreshadow data available in each row. I'm not sure
  //   this has been finished/merged yet. But this code assumes it's working.
  // TODO: CRM_Activity_Tokens should consume activity_id
  // TODO: CRM_Case_Tokens should consume case_id
  // TODO: CRM_Contribute_Tokens should consume contribution_id; like old call to replaceContributionTokens
  // TODO: Email.send previously called replaceComponentTokens(). Determine if that's something we care about.

  // The field names in $params and in the token context don't exactly match;
  // so we'll map them.
  $activeEntityFields = _civicrm_api3_email_send_findActiveEntityFields($params, array(
    // string $api_param_name => string $tokenContextName
    'activity_id' => 'activityId',
    'case_id' => 'caseId',
    'contribution_id' => 'contributionId',
  ));

  // Prepare the processor and general context.
  $tokenProc = new \Civi\Token\TokenProcessor(\Civi::dispatcher(), [
    // Unique(ish) identifier for our controller/use-case.
    'controller' => 'civicrm_api3_email_send',

    // Provide hints about what data will be available for each row.
    // Ex: 'schema' => ['contactId', 'activityId', 'caseId'],
    'schema' => array_values($activeEntityFields),

    // Whether to enable Smarty evaluation.
    'smarty' => (defined('CIVICRM_MAIL_SMARTY') && CIVICRM_MAIL_SMARTY),
  ]);

  // Define message templates.
  $tokenProc->addMessage('subject', $messageTemplate->msg_subject, 'text/plain');
  $tokenProc->addMessage('body_html', $messageTemplate->msg_html, 'text/html');
  $tokenProc->addMessage('body_text',
    $messageTemplate->msg_text ? $messageTemplate->msg_text : CRM_Utils_String::htmlToText($messageTemplate->msg_html),
    'text/plain');

  // Define row data.
  foreach (explode(',', $params['contact_id']) as $contactId) {
    $context = ['contactId' => $contactId];
    foreach ($activeEntityFields as $paramName => $contextName) {
      $context[$contextName] = $params[$paramName];
    }
    $tokenProc->addRow()->context($context);
  }

  return $tokenProc;
}

/**
 * @param array $params
 *   API input.
 *   Ex: ['contact_id' => 123, 'contribution_id' => 12345]
 * @param array $availableEntityFields
 *   List of fields that we would be interesting to us.
 *   Array(string $apiParamName => string $tokenContextName).
 *   Ex: ['contribution_id' => 'contributionId']
 * @return array
 *   Ex: ['contribution_id' => 'contributionId']
 */
function _civicrm_api3_email_send_findActiveEntityFields($params, $availableEntityFields) {
  $activeEntityFields = [];
  foreach ($availableEntityFields as $paramName => $contextName) {
    if (isset($params[$paramName])) {
      $activeEntityFields[$paramName] = $contextName;
    }
  }
  return $activeEntityFields;
}
