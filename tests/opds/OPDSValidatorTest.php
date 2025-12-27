<?php
declare(strict_types=1);

require_once(__DIR__ . '/../../application/opds/core/autoload.php');

use PHPUnit\Framework\TestCase;

/**
 * Unit тесты для OPDSValidator
 */
class OPDSValidatorTest extends TestCase {
    
    /**
     * Тест валидации ID
     */
    public function testValidateId(): void {
        $_GET['test_id'] = '123';
        
        $id = OPDSValidator::validateId('test_id');
        
        $this->assertEquals(123, $id);
    }
    
    /**
     * Тест валидации ID с минимальным значением
     */
    public function testValidateIdMinValue(): void {
        $_GET['test_id'] = '0';
        
        $this->expectException(\InvalidArgumentException::class);
        OPDSValidator::validateId('test_id', 1);
    }
    
    /**
     * Тест валидации строки
     */
    public function testValidateString(): void {
        $_GET['test_string'] = 'test value';
        
        $value = OPDSValidator::validateString('test_string');
        
        $this->assertEquals('test value', $value);
    }
    
    /**
     * Тест валидации номера страницы
     */
    public function testValidatePage(): void {
        $_GET['page'] = '5';
        
        $page = OPDSValidator::validatePage();
        
        $this->assertEquals(5, $page);
    }
    
    /**
     * Тест валидации номера страницы по умолчанию
     */
    public function testValidatePageDefault(): void {
        unset($_GET['page']);
        
        $page = OPDSValidator::validatePage('page', 1);
        
        $this->assertEquals(1, $page);
    }
    
    /**
     * Тест валидации поискового запроса
     */
    public function testValidateSearchQuery(): void {
        $_GET['q'] = 'test search';
        
        $query = OPDSValidator::validateSearchQuery('q', 1, 255, true);
        
        $this->assertEquals('test search', $query);
    }
    
    /**
     * Тест валидации пустого поискового запроса
     */
    public function testValidateSearchQueryEmpty(): void {
        $_GET['q'] = '';
        
        $this->expectException(\InvalidArgumentException::class);
        OPDSValidator::validateSearchQuery('q', 1, 255, true);
    }
    
    protected function tearDown(): void {
        // Очищаем $_GET после каждого теста
        $_GET = [];
    }
}
