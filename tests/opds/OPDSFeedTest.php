<?php
declare(strict_types=1);

require_once(__DIR__ . '/../../application/opds/core/autoload.php');
require_once(__DIR__ . '/../../application/init.php');

use PHPUnit\Framework\TestCase;

/**
 * Unit тесты для OPDSFeed и связанных классов
 */
class OPDSFeedTest extends TestCase {
    
    /**
     * Тест создания базового фида
     */
    public function testCreateFeed(): void {
        $feed = OPDSFeedFactory::create();
        
        $feed->setId('tag:test:feed');
        $feed->setTitle('Test Feed');
        $feed->setUpdated('2024-01-01T00:00:00Z');
        
        $this->assertEquals('tag:test:feed', $feed->getId());
        $this->assertEquals('Test Feed', $feed->getTitle());
        $this->assertEquals('2024-01-01T00:00:00Z', $feed->getUpdated());
    }
    
    /**
     * Тест добавления entry в фид
     */
    public function testAddEntry(): void {
        $feed = OPDSFeedFactory::create();
        $feed->setId('tag:test:feed');
        $feed->setTitle('Test Feed');
        
        $entry = new OPDSEntry();
        $entry->setId('tag:test:entry:1');
        $entry->setTitle('Test Entry');
        $entry->setUpdated('2024-01-01T00:00:00Z');
        
        $feed->addEntry($entry);
        
        // Проверяем, что entry добавлен
        $this->assertNotEmpty($feed->render());
        $this->assertStringContainsString('tag:test:entry:1', $feed->render());
    }
    
    /**
     * Тест добавления ссылок в фид
     */
    public function testAddLink(): void {
        $feed = OPDSFeedFactory::create();
        $feed->setId('tag:test:feed');
        $feed->setTitle('Test Feed');
        
        $link = new OPDSLink('/test', 'self', OPDSVersion::getProfile('navigation'));
        $feed->addLink($link);
        
        $xml = $feed->render();
        $this->assertStringContainsString('rel="self"', $xml);
        $this->assertStringContainsString('href="/test"', $xml);
    }
    
    /**
     * Тест правильного порядка элементов в XML
     */
    public function testXmlElementOrder(): void {
        $feed = OPDSFeedFactory::create();
        $feed->setId('tag:test');
        $feed->setTitle('Test');
        $feed->setUpdated('2024-01-01T00:00:00Z');
        $feed->setIcon('/icon.ico');
        
        $xml = $feed->render();
        
        // Проверяем порядок: id, title, updated, icon, затем links
        $idPos = strpos($xml, '<id>');
        $titlePos = strpos($xml, '<title>');
        $updatedPos = strpos($xml, '<updated>');
        $iconPos = strpos($xml, '<icon>');
        
        $this->assertNotFalse($idPos);
        $this->assertNotFalse($titlePos);
        $this->assertNotFalse($updatedPos);
        $this->assertNotFalse($iconPos);
        
        $this->assertLessThan($titlePos, $idPos);
        $this->assertLessThan($updatedPos, $titlePos);
        $this->assertLessThan($iconPos, $updatedPos);
    }
    
    /**
     * Тест добавления групп в фид
     */
    public function testAddGroup(): void {
        $feed = OPDSFeedFactory::create();
        $feed->setId('tag:test:feed');
        $feed->setTitle('Test Feed');
        
        $group = new OPDSGroup('Test Group');
        $entry = new OPDSEntry();
        $entry->setId('tag:test:entry:1');
        $entry->setTitle('Test Entry');
        $group->addEntry($entry);
        
        $feed->addGroup($group);
        
        $xml = $feed->render();
        $this->assertStringContainsString('<opds:group>', $xml);
        $this->assertStringContainsString('<opds:title>Test Group</opds:title>', $xml);
    }
}
