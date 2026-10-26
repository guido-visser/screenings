<?php

declare(strict_types=1);

namespace OCA\Screenings\Mail;

use OCA\Screenings\Db\MailTemplate;
use OCA\Screenings\Db\MailTemplateMapper;
use OCA\Screenings\Db\Screening;
use OCA\Screenings\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The invitation email's design. A screening uses its own design if it has
 * one, else its owner's default, else the built-in one (which is written in
 * the same template language, so owners can start from it).
 */
class MailTemplateService {
	public const MAX_HTML = 200 * 1024;
	public const MAX_SUBJECT = 255;
	public const BUILTIN_SUBJECT = '{{owner}} invited you to a private screening of "{{title}}"';

	public const SOURCE_SCREENING = 'screening';
	public const SOURCE_DEFAULT = 'default';
	public const SOURCE_BUILTIN = 'builtin';

	/** Placeholder => what it contains, for the editor. */
	public const VARIABLES = [
		'title' => 'Title of the screening',
		'description' => 'Description of the screening (may be empty)',
		'duration' => 'Length of the film, e.g. "26 min" (may be empty)',
		'owner' => 'Your name',
		'owner_email' => 'Your email address',
		'name' => 'Name of the invited viewer',
		'email' => 'Email address of the invited viewer',
		'link' => 'The viewer\'s personal link to the screening',
		'message' => 'The personal message you write when sending (may be empty)',
		'expires' => 'Date and time the link stops working (empty without an end date)',
		'password' => 'Set when the screening has a password (use as {{#password}}…{{/password}})',
		'tracking_notice' => 'The screening\'s tracking notice (empty when it is turned off)',
	];

	public function __construct(
		private MailTemplateMapper $mapper,
		private ITimeFactory $time,
	) {
	}

	/**
	 * The design invitations for this screening are sent with.
	 *
	 * @return array{subject: string, html: string, source: string}
	 */
	public function resolve(Screening $screening): array {
		foreach ([$screening->getId(), null] as $screeningId) {
			try {
				$row = $this->mapper->findFor($screening->getOwnerUid(), $screeningId);
				return ['subject' => $row->getSubject(), 'html' => $row->getHtml(),
					'source' => $screeningId === null ? self::SOURCE_DEFAULT : self::SOURCE_SCREENING];
			} catch (DoesNotExistException) {
			}
		}
		return self::builtin() + ['source' => self::SOURCE_BUILTIN];
	}

	/**
	 * The design as shown in the editor: a screening's (or what it falls
	 * back to), or the owner's default (or the built-in one).
	 *
	 * @return array{subject: string, html: string, source: string}
	 */
	public function forEditor(string $uid, ?Screening $screening): array {
		if ($screening !== null) {
			return $this->resolve($screening);
		}
		try {
			$row = $this->mapper->findFor($uid, null);
			return ['subject' => $row->getSubject(), 'html' => $row->getHtml(), 'source' => self::SOURCE_DEFAULT];
		} catch (DoesNotExistException) {
			return self::builtin() + ['source' => self::SOURCE_BUILTIN];
		}
	}

	/**
	 * @throws ValidationException
	 */
	public function save(string $uid, ?Screening $screening, string $subject, string $html): void {
		[$subject, $html] = self::validated($subject, $html);
		$screeningId = $screening?->getId();
		try {
			$row = $this->mapper->findFor($uid, $screeningId);
		} catch (DoesNotExistException) {
			$row = new MailTemplate();
			$row->setOwnerUid($uid);
			$row->setScreeningId($screeningId);
		}
		$row->setSubject($subject);
		$row->setHtml($html);
		$row->setUpdatedAt($this->time->getTime());
		$row->getId() === null ? $this->mapper->insert($row) : $this->mapper->update($row);
	}

	/**
	 * Back to the fallback: the default design for a screening, the
	 * built-in one for the default.
	 */
	public function reset(string $uid, ?Screening $screening): void {
		try {
			$this->mapper->delete($this->mapper->findFor($uid, $screening?->getId()));
		} catch (DoesNotExistException) {
		}
	}

	/**
	 * @param array<string, string|bool|null> $values
	 * @return array{subject: string, html: string, text: string}
	 * @throws ValidationException
	 */
	public static function render(string $subject, string $html, array $values): array {
		$renderedHtml = TemplateRenderer::render(TemplateRenderer::clean($html), $values);
		return [
			// One line, as mail headers need.
			'subject' => trim(preg_replace('/\s+/', ' ', TemplateRenderer::render($subject, $values, false))),
			'html' => $renderedHtml,
			'text' => TemplateRenderer::toText($renderedHtml),
		];
	}

	/**
	 * @return string[] placeholders used in the design that don't exist
	 */
	public static function unknownNames(string $subject, string $html): array {
		return TemplateRenderer::unknownNames($subject . "\n" . $html, array_keys(self::VARIABLES));
	}

	/**
	 * @return array{subject: string, html: string}
	 */
	public static function builtin(): array {
		return ['subject' => self::BUILTIN_SUBJECT, 'html' => (string)file_get_contents(__DIR__ . '/default-invitation.html')];
	}

	/**
	 * @return array{string, string}
	 * @throws ValidationException
	 */
	private static function validated(string $subject, string $html): array {
		$subject = trim($subject);
		if ($subject === '' || mb_strlen($subject) > self::MAX_SUBJECT) {
			throw new ValidationException('Enter a subject of at most ' . self::MAX_SUBJECT . ' characters', 'subject');
		}
		if (trim($html) === '') {
			throw new ValidationException('The email needs some content', 'html');
		}
		if (strlen($html) > self::MAX_HTML) {
			throw new ValidationException('The email can be at most 200 KB', 'html');
		}
		if (!str_contains($html, '{{link}}') && !preg_match('/\{\{\s*link\s*\}\}/', $html)) {
			throw new ValidationException('The email must contain {{link}}, or viewers can\'t open the screening', 'html');
		}
		TemplateRenderer::validate($subject);
		TemplateRenderer::validate($html);
		return [$subject, $html];
	}
}
