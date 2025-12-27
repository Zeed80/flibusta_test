<?php
declare(strict_types=1);

require_once(__DIR__ . '/../../application/opds/core/autoload.php');

use PHPUnit\Framework\TestCase;

/**
 * Unit тесты для OPDSEntry
 */
class OPDSEntryTest extends TestCase {
    
    /**
     * Тест создания entry
     */
    public function testCreateEntry(): void {
        $entry = new OPDSEntry();
        $entry->setId('tag:test:entry:1');
        $entry->setTitle('Test Entry');
        $entry->setUpdated('2024-01-01T00:00:00Z');
        
        $this->assertEquals('tag:test:entry:1', $entry->getId());
        $this->assertEquals('Test Entry', $entry->getTitle());
        $this->assertEquals('2024-01-01T00:00:00Z', $entry->getUpdated());
    }
    
    /**
     * Тест добавления автора
     */
    public function testAddAuthor(): void {
        $entry = new OPDSEntry();
        $entry->setId('tag:test:entry:1');
        $entry->setTitle('Test Entry');
        
        $entry->addAuthor('Иван Иванов', '/author/1');
        
        $xml = $entry->render();
        $this->assertStringContainsString('<author>', $xml);
        $this->assertStringContainsString('<name>Иван Иванов</name>', $xml);
        $this->assertStringContainsString('<uri>/author/1</uri>', $xml);
    }
    
    /**
     * Тест добавления категории
     */
    public function testAddCategory(): void {
        $entry = new OPDSEntry();
        $entry->setId('tag:test:entry:1');
        $entry->setTitle('Test Entry');
        
        $entry->addCategory('fiction', 'Художественная литература');
        
        $xml = $entry->render();
        $this->assertStringContainsString('<category', $xml);
        $this->assertStringContainsString('term="fiction"', $xml);
        $this->assertStringContainsString('label="Художественная литература"', $xml);
    }
    
    /**
     * Тест добавления ссылки
     */
    public function testAddLink(): void {
        $entry = new OPDSEntry();
        $entry->setId('tag:test:entry:1');
        $entry->setTitle('Test Entry');
        
        $link = new OPDSLink('/book/1', 'alternate', 'text/html');
        $entry->addLink($link);
        
        $xml = $entry->render();
        $this->assertStringContainsString('href="/book/1"', $xml);
        $this->assertStringContainsString('rel="alternate"', $xml);
    }
}
