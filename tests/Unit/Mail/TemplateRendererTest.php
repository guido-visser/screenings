<?php

declare(strict_types=1);

namespace OCA\Screenings\Tests\Unit\Mail;

use OCA\Screenings\Exception\ValidationException;
use OCA\Screenings\Mail\TemplateRenderer;
use PHPUnit\Framework\TestCase;

class TemplateRendererTest extends TestCase {
	public function testPlaceholdersAreEscaped(): void {
		$out = TemplateRenderer::render('<p>Hi {{name}}, watch {{ title }}</p>', ['name' => '<b>Eve</b> & co', 'title' => 'Bloedtranen']);
		$this->assertSame('<p>Hi &lt;b&gt;Eve&lt;/b&gt; &amp; co, watch Bloedtranen</p>', $out);
	}

	public function testLineBreaksAndPlainText(): void {
		$this->assertSame("line one<br>\nline two", TemplateRenderer::render('{{message}}', ['message' => "line one\nline two"]));
		$this->assertSame('Eve & co: <x>', TemplateRenderer::render('{{name}}: <x>', ['name' => 'Eve & co'], false), 'subject: not escaped');
	}

	public function testUnknownPlaceholdersAreEmptyAndReported(): void {
		$this->assertSame('Hi !', TemplateRenderer::render('Hi {{nmae}}!', ['name' => 'Eve']));
		$this->assertSame(['nmae', 'poster'], TemplateRenderer::unknownNames('{{nmae}} {{#poster}}x{{/poster}} {{name}}', ['name']));
	}

	public function testSections(): void {
		$tpl = '{{#expires}}Until {{expires}}.{{/expires}}{{^expires}}No end date.{{/expires}}';
		$this->assertSame('Until 1 May.', TemplateRenderer::render($tpl, ['expires' => '1 May']));
		$this->assertSame('No end date.', TemplateRenderer::render($tpl, ['expires' => '']));
		$this->assertSame('No end date.', TemplateRenderer::render($tpl, []));
		$this->assertSame('Ask for the password.', TemplateRenderer::render('{{#password}}Ask for the password.{{/password}}', ['password' => true]));
		$this->assertSame('', TemplateRenderer::render('{{#password}}Ask for the password.{{/password}}', ['password' => false]));
	}

	public function testNestedSections(): void {
		$tpl = '{{#a}}A{{#b}}B{{/b}}{{^b}}-{{/b}}{{/a}}';
		$this->assertSame('AB', TemplateRenderer::render($tpl, ['a' => 'x', 'b' => 'y']));
		$this->assertSame('A-', TemplateRenderer::render($tpl, ['a' => 'x']));
		$this->assertSame('', TemplateRenderer::render($tpl, ['b' => 'y']));
	}

	public function testRejectsUnbalancedSections(): void {
		$this->expectException(ValidationException::class);
		TemplateRenderer::render('{{#expires}}never closed', []);
	}

	public function testRejectsCrossedSections(): void {
		$this->expectException(ValidationException::class);
		TemplateRenderer::validate('{{#a}}{{#b}}{{/a}}{{/b}}');
	}

	public function testToText(): void {
		$html = '<html><head><style>p{color:red}</style></head><body><h1>Hello Eve</h1><p>Line<br>two</p>'
			. '<p><a href="https://x.test/s/abc">Watch the screening</a></p><p><a href="https://x.test">https://x.test</a></p></body></html>';
		$this->assertSame("Hello Eve\n\nLine\ntwo\n\nWatch the screening (https://x.test/s/abc)\n\nhttps://x.test", TemplateRenderer::toText($html));
		// A rendered multi-line message: nl2br leaves a newline after each <br>.
		$message = TemplateRenderer::render("<p>\n\t{{m}}\n</p>", ['m' => "Hi!\nSecond line"]);
		$this->assertSame("Hi!\nSecond line", TemplateRenderer::toText($message));
	}

	public function testClean(): void {
		$this->assertSame('<p>ok</p><a href="x">y</a>', TemplateRenderer::clean('<p onclick="evil()">ok</p><script>alert(1)</script><a href="x" onmouseover=\'x\'>y</a><iframe src="z"></iframe>'));
	}
}
