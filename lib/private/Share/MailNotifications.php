<?php
/**
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Roeland Jago Douma <rullzer@owncloud.com>
 * @author scolebrook <scolebrook@mac.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\Share;

use DateTime;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Mail\IMailer;
use OCP\ILogger;
use OCP\Defaults;
use OCP\Util;
use OC\Share\Filters\MailNotificationFilter;

/**
 * Class MailNotifications
 *
 * @package OC\Share
 */
class MailNotifications {

	/** @var IUser sender userId */
	private $user;
	/** @var string sender email address */
	private $replyTo;
	/** @var string */
	private $senderDisplayName;
	/** @var IL10N */
	private $l;
	/** @var IMailer */
	private $mailer;
	/** @var Defaults */
	private $defaults;
	/** @var ILogger */
	private $logger;
	/** @var IURLGenerator */
	private $urlGenerator;

	/**
	 * @param IUser $user
	 * @param IL10N $l10n
	 * @param IMailer $mailer
	 * @param ILogger $logger
	 * @param Defaults $defaults
	 * @param IURLGenerator $urlGenerator
	 */
	public function __construct(IUser $user,
								IL10N $l10n,
								IMailer $mailer,
								ILogger $logger,
								Defaults $defaults,
								IURLGenerator $urlGenerator) {
		$this->l = $l10n;
		$this->user = $user;
		$this->mailer = $mailer;
		$this->logger = $logger;
		$this->defaults = $defaults;
		$this->urlGenerator = $urlGenerator;

		$this->replyTo = $this->user->getEMailAddress();

		$filter = new MailNotificationFilter([
			'senderDisplayName' => $this->user->getDisplayName(),
		]);

		$this->senderDisplayName = $filter->getSenderDisplayName();
	}

	/**
	 * split a list of comma or semicolon separated email addresses
	 *
	 * @param string $mailsstring email addresses
	 * @return array list of individual addresses
	 */
	private function _mailStringToArray($mailsstring) {
		$sanatised  = \str_replace([', ', '; ', ',', ';', ' '], ',', $mailsstring);
		$mail_array = \explode(',', $sanatised);

		return $mail_array;
	}

	/**
	 * inform users if a file was shared with them
	 *
	 * @param IUser[] $recipientList list of recipients
	 * @param string $itemSource shared item source
	 * @param string $itemType shared item type
	 * @return array list of user to whom the mail send operation failed
	 */
	public function sendInternalShareMail($recipientList, $itemSource, $itemType) {
		$noMail = [];

		foreach ($recipientList as $recipient) {
			$recipientDisplayName = $recipient->getDisplayName();
			$to = $recipient->getEMailAddress();

			if ($to === null || $to === '') {
				$noMail[] = $recipientDisplayName;
				continue;
			}

			$items = $this->getItemSharedWithUser($itemSource, $itemType, $recipient);
			$filename = \trim($items[0]['file_target'], '/');
			$expiration = null;
			if (isset($items[0]['expiration'])) {
				try {
					$date = new DateTime($items[0]['expiration']);
					$expiration = $date->getTimestamp();
				} catch (\Exception $e) {
					$this->logger->error("Couldn't read date: ".$e->getMessage(), ['app' => 'sharing']);
				}
			}

			$link = $this->urlGenerator->linkToRouteAbsolute(
				'files.viewcontroller.showFile',
				['fileId' => $items[0]['item_source']]
			);

			$filter = new MailNotificationFilter([
				'link' => $link,
				'file' => $filename,
			]);

			$filename = $filter->getFile();
			$link = $filter->getLink();

			$subject = (string) $this->l->t('%s shared »%s« with you', [$this->senderDisplayName, $filename]);
			list($htmlBody, $textBody) = $this->createMailBody($filename, $link, $expiration, null, 'internal');

			// send it out now
			try {
				$message = $this->mailer->createMessage();
				$message->setSubject($subject);
				$message->setTo([$to => $recipientDisplayName]);
				$message->setHtmlBody($htmlBody);
				$message->setPlainBody($textBody);
				$message->setFrom([
					Util::getDefaultEmailAddress('sharing-noreply') =>
						(string)$this->l->t('%s via %s', [
							$this->senderDisplayName,
							$this->defaults->getName()
						]),
					]);
				if ($this->replyTo !== null) {
					$message->setReplyTo([$this->replyTo]);
				}

				$this->mailer->send($message);
			} catch (\Exception $e) {
				$this->logger->error("Can't send mail to inform the user about an internal share: ".$e->getMessage(), ['app' => 'sharing']);
				$noMail[] = $recipientDisplayName;
			}
		}

		return $noMail;
	}

	public function sendLinkShareMail($recipient, $filename, $link, $expiration, $personalNote = null, $options = []) {
		$subject = (string)$this->l->t('%s shared »%s« with you', [$this->senderDisplayName, $filename]);
		list($htmlBody, $textBody) = $this->createMailBody($filename, $link, $expiration, $personalNote);

		return $this->sendLinkShareMailFromBody($recipient, $subject, $htmlBody, $textBody, $options);
	}

	/**
	 * inform recipient about public link share
	 *
	 * @param string $recipient recipient email address
	 * @param string $filename the shared file
	 * @param string $link the public link
	 * @param array $options allows ['cc'] and ['bcc'] recipients
	 * @param int $expiration expiration date (timestamp)
	 * @return string[] $result of failed recipients
	 */
	public function sendLinkShareMailFromBody($recipient, $subject, $htmlBody, $textBody, $options = []) {
		$recipients = [];
		if ($recipient !== null) {
			$recipients    = $this->_mailStringToArray($recipient);
		}
		$ccRecipients  = (isset($options['cc']) && $options['cc'] !== '') ? $this->_mailStringToArray($options['cc']) : null;
		$bccRecipients = (isset($options['bcc']) && $options['bcc'] !== '') ? $this->_mailStringToArray($options['bcc']) : null;

		try {
			$message = $this->mailer->createMessage();
			$message->setSubject($subject);
			$message->setTo($recipients);
			if ($htmlBody !== null) {
				$message->setHtmlBody($htmlBody);
			}
			if ($bccRecipients !== null) {
				$message->setBcc($bccRecipients);
			}
			if ($ccRecipients !== null) {
				$message->setCc($ccRecipients);
			}
			$message->setPlainBody($textBody);
			$message->setFrom([
				Util::getDefaultEmailAddress('sharing-noreply') =>
					(string)$this->l->t('%s via %s', [
						$this->senderDisplayName,
						$this->defaults->getName()
					]),
			]);
			if ($this->replyTo !== null) {
				$message->setReplyTo([$this->replyTo]);
			}

			return $this->mailer->send($message);
		} catch (\Exception $e) {
			$this->logger->error("Can't send mail with public link to $recipient: ".$e->getMessage(), ['app' => 'sharing']);
			return [$recipient];
		}
	}

	/**
	 * create mail body for plain text and html mail
	 *
	 * @param string $filename the shared file
	 * @param string $link link to the shared file
	 * @param int $expiration expiration date (timestamp)
	 * @param string $personalNote optional personal note
	 * @param string $prefix prefix of mail template files
	 * @return array an array of the html mail body and the plain text mail body
	 */
	public function createMailBody($filename, $link, $expiration, $personalNote = null, $prefix = '') {
		$formattedDate = $expiration ? $this->l->l('date', $expiration) : null;

		$html = new \OC_Template('core', $prefix . 'mail', '');
		$html->assign('link', $link);
		$html->assign('user_displayname', $this->senderDisplayName);
		$html->assign('filename', $filename);
		$html->assign('expiration', $formattedDate);
		if ($personalNote !== null && $personalNote !== '') {
			$html->assign('personal_note', $personalNote);
		}
		$htmlMail = $html->fetchPage();

		$plainText = new \OC_Template('core', $prefix . 'altmail', '');
		$plainText->assign('link', $link);
		$plainText->assign('user_displayname', $this->senderDisplayName);
		$plainText->assign('filename', $filename);
		$plainText->assign('expiration', $formattedDate);
		if ($personalNote !== null && $personalNote !== '') {
			$plainText->assign('personal_note', $personalNote);
		}
		$plainTextMail = $plainText->fetchPage();

		return [$htmlMail, $plainTextMail];
	}

	/**
	 * @param string $itemSource
	 * @param string $itemType
	 * @param IUser $recipient
	 * @return array
	 */
	protected function getItemSharedWithUser($itemSource, $itemType, $recipient) {
		return Share::getItemSharedWithUser($itemType, $itemSource, $recipient->getUID());
	}
}
