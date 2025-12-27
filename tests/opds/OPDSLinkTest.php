<?php
declare(strict_types=1);

require_once(__DIR__ . '/../../application/opds/core/autoload.php');

use PHPUnit\Framework\TestCase;

/**
 * Unit тесты для OPDSLink
 */
class OPDSLinkTest extends TestCase {
    
    /**
     * Тест создания ссылки
     */
    public function testCreateLink(): void {
        $link = new OPDSLink('/test', 'self', 'application/atom+xml');
        
        $this->assertEquals('/test', $link->getHref());
        $this->assertEquals('self', $link->getRel());
        $this->assertEquals('application/atom+xml', $link->getType());
    }
    
    /**
     * Тест рендеринга ссылки в XML
     */
    public function testRenderLink(): void {
        $link = new OPDSLink('/test', 'self', 'application/atom+xml', 'Test Link');
        
        $xml = $link->render();
        
        $this->assertStringContainsString('href="/test"', $xml);
        $this->assertStringContainsString('rel="self"', $xml);
        $this->assertStringContainsString('type="application/atom+xml"', $xml);
        $this->assertStringContainsString('title="Test Link"', $xml);
    }
    
    /**
     * Тест экранирования спецсимволов в ссылке
     */
    public function testLinkEscaping(): void {
        $link = new OPDSLink('/test?q=test&page=1', 'self', 'application/atom+xml');
        
        $xml = $link->render();
        
        // Проверяем, что амперсанды экранированы
        $this->assertStringContainsString('&amp;', $xml);
    }
}
