<?php /** @noinspection PhpUnused */

declare(strict_types=1);

namespace Codeception\Module;

use Codeception\Exception\ModuleException;
use Codeception\Exception\ModuleRequireException;
use Codeception\Lib\Framework;
use Codeception\Lib\InnerBrowser;
use Codeception\Lib\Interfaces\DependsOnModule;
use Codeception\Module;
use Codeception\TestInterface;
use Codeception\Util\Soap as SoapUtils;
use Codeception\Util\XmlBuilder;
use Codeception\Util\XmlStructure;
use DOMDocument;
use ErrorException;
use PHPUnit\Framework\Assert;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\BrowserKit\Response;
use uhi67\soapHelper\SoapClientDry;

/**
 * Module for testing SOAP WSDL web services.
 * Send requests and check if response matches the pattern.
 *
 * This module can be used either with frameworks or PHPBrowser.
 * It tries to guess the framework is is attached to.
 * If a endpoint is a full url then it uses PHPBrowser.
 *
 * ### Using Inside Framework
 *
 * Please note, that PHP SoapServer::handle method sends additional headers.
 * This may trigger warning: "Cannot modify header information"
 * If you use PHP SoapServer with framework, try to block call to this method in testing environment.
 *
 * ## Status
 *
 * * Maintainer: **davert**
 * * Stability: **stable**
 * * Contact: codecept@davert.mail.ua
 *
 * ## Configuration
 *
 * * endpoint *required* - soap wsdl endpoint
 * * schema *required* -- soap target namespace
 * * SOAPAction - replace SOAPAction HTTP header (Set to '' to SOAP 1.2)
 *
 * If multiple endpoints are used, configuration must be updated runtime by `$I->setSoapConfig(['endpoint'=>...,...]);`
 *
 * ## Public Properties
 *
 * * xmlRequest - last SOAP request (DOMDocument)
 * * xmlResponse - last SOAP response (DOMDocument)
 *
 */
class SOAP extends Module implements DependsOnModule
{
    const
        SCHEME_SOAP_ENCODING = "http://schemas.xmlsoap.org/soap/encoding/",
        SCHEME_SOAP_ENVELOPE = "http://schemas.xmlsoap.org/soap/envelope/",
        SCHEME_XSD = "http://www.w3.org/2001/XMLSchema",
        SCHEME_XSI = "http://www.w3.org/2001/XMLSchema-instance";

    /**
     * @var array
     */
    protected $config = [
        'schema' => "",
        'schema_url' => self::SCHEME_SOAP_ENVELOPE,
        'framework_collect_buffer' => true
    ];

    /**
     * @var string[]
     */
    protected $requiredFields = ['endpoint', 'schema'];

    /**
     * @var string
     */
    protected $dependencyMessage = <<<EOF
Example using PhpBrowser as backend for SOAP module.
--
modules:
    enabled:
        - SOAP:
            depends: PhpBrowser
--
Framework modules can be used as well for functional testing of SOAP API.
EOF;

    /**
     * @var AbstractBrowser
     */
    public $client;

    /**
     * @var bool
     */
    public $isFunctional = false;

    /**
     * @var DOMDocument
     */
    public $xmlRequest;
    /**
     * @var DOMDocument
     */
    public $xmlResponse;

    /**
     * @var XmlStructure
     */
    protected $xmlStructure;

    /**
     * @var InnerBrowser
     */
    protected $connectionModule;

    // Parameters of last fake call
    private $wsdl;
    private $method;

    public function _before(TestInterface $test): void
    {
        $this->client = &$this->connectionModule->client;
        $this->buildRequest();
        $this->xmlResponse = null;
        $this->xmlStructure = null;
    }

    protected function onReconfigure(): void
    {
        $this->buildRequest();
        $this->xmlResponse = null;
        $this->xmlStructure = null;
    }

    public function _depends(): array
    {
        return [InnerBrowser::class => $this->dependencyMessage];
    }

    public function _inject(InnerBrowser $connectionModule): void
    {
        $this->connectionModule = $connectionModule;
        if ($connectionModule instanceof Framework) {
            $this->isFunctional = true;
        }
    }

    /**
     * @throws ModuleRequireException
     */
    private function getClient(): AbstractBrowser
    {
        if (!$this->client) {
            throw new ModuleRequireException($this, 'Connection client is not available.');
        }
        return $this->client;
    }

    /**
     * @throws ModuleException
     */
    private function getXmlResponse(): DOMDocument
    {
        if (!$this->xmlResponse) {
            throw new ModuleException($this, "No XML response, use `\$I->sendSoapRequest` to receive it");
        }
        return $this->xmlResponse;
    }

    /**
     * @throws ModuleException
     */
    private function getXmlStructure(): XmlStructure
    {
        if (!$this->xmlStructure) {
            $this->xmlStructure = new XmlStructure($this->getXmlResponse());
        }
        return $this->xmlStructure;
    }

    /**
     * Prepare SOAP header.
     * Receives header name and parameters as array.
     *
     * Example:
     *
     * ``` php
     * <?php
     * $I->haveSoapHeader('AuthHeader', array('username' => 'davert', 'password' => '123345'));
     * ```
     *
     * Will produce header:
     *
     * ```
     *    <soapenv:Header>
     *      <SessionHeader>
     *      <AuthHeader>
     *          <username>davert</username>
     *          <password>12345</password>
     *      </AuthHeader>
     *   </soapenv:Header>
     * ```
     *
     * @param string $header
     * @param array $params
     */
    public function haveSoapHeader(string $header, array $params = []): void
    {
        $soap_schema_url = $this->config['schema_url'];
        $xml = $this->xmlRequest;
        $domElement = $xml->documentElement->getElementsByTagNameNS($soap_schema_url, 'Header')->item(0);
        $headerEl = $xml->createElement($header);
        SoapUtils::arrayToXml($xml, $headerEl, $params);
        $domElement->appendChild($headerEl);
    }

    /**
     * Submits test request to endpoint
     *
     * Requires of api function name and parameters.
     * Parameters can be passed either as DOMDocument, DOMNode, XML string, or array
     *
     * You are allowed to execute as much requests as you need inside test.
     *
     * ### Example:
     *
     * ``` php
     * $I->sendSoapRequest('UpdateUser', '<user><id>1</id><name>notdavert</name></user>');
     * $I->sendSoapRequest('UpdateUser', \Codeception\Utils\Soap::request()->user
     *   ->id->val(1)->parent()
     *   ->name->val('notdavert');
     * ```
     *
     * ### Using array parameters:
     *
     * - single argument is a one-element array
     * - using WSDL (URI, best is full-path filename) is recommended, otherwise non-wsdl request will be created
     *
     * @param string|null $wsdl -- URI (full filename path is possible)
     * @param string $action
     * @param object|array|string $body
     * @param string $wsdl -- needed if body is an array (unless non-wsdl-mode request will be generated)
     *
     * @throws \ModuleRequireException|\SoapFault
     */
    public function sendSoapRequest(string $action, $body = '', $wsdl=null): void
    {
        if ($body || is_array($body)) {
            if(is_array($body)) {
                $xml = SoapClientDry::__requestXml($body, $action, $wsdl, null, true);
                $this->xmlRequest = $xml;
                $this->wsdl = $wsdl;
                $this->method = $action;
            }
            else {
                $soap_schema_url = $this->config['schema_url'];
                $xml = $this->xmlRequest;
                $call = $xml->createElementNS($this->getSchema(), 'ns:' . $action);

                $bodyXml = SoapUtils::toXml($body);
                if ($bodyXml->hasChildNodes()) {
                    foreach ($bodyXml->childNodes as $bodyChildNode) {
                        $call->appendChild($xml->importNode($bodyChildNode, true));
                    }
                }
                $xmlBody = $xml->getElementsByTagNameNS($soap_schema_url, 'Body')->item(0);

                // cleanup if body already set
                foreach ($xmlBody->childNodes as $node) {
                    $xmlBody->removeChild($node);
                }
                $xmlBody->appendChild($call);
            }
            $this->debugSection('Request', $req = $xml->C14N());
        }

        if ($this->isFunctional && $this->config['framework_collect_buffer']) {
            $response = $this->processInternalRequest($action, $req);
        } else {
            $response = $this->processExternalRequest($action, $req);
        }

        $this->debugSection('Response', (string) $response);
        $this->xmlResponse = SoapUtils::toXml($response);
        $this->xmlStructure = null;
    }

    /**
     * Checks XML response equals provided XML.
     * Comparison is done by canonicalizing both xml`s.
     *
     * Parameters can be passed either as DOMDocument, DOMNode, XML string, or array (if no attributes).
     *
     * Example:
     *
     * ``` php
     * <?php
     * $I->seeSoapResponseEquals("<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope><SOAP-ENV:Body><result>1</result></SOAP-ENV:Envelope>");
     *
     * $dom = new \DOMDocument();
     * $dom->load($file);
     * $I->seeSoapRequestIncludes($dom);
     *
     * ```
     *
     * @param string $xml
     *
     * @throws ModuleException
     */
    public function seeSoapResponseEquals(string $xml): void
    {
        $xml = SoapUtils::toXml($xml);
        $this->assertEquals($xml->C14N(), $this->getXmlResponse()->C14N());
    }

    /**
     * Checks XML response includes provided XML.
     * Comparison is done by canonicalizing both xml`s.
     * Parameter can be passed either as XmlBuilder, DOMDocument, DOMNode, XML string, or array (if no attributes).
     *
     * Example:
     *
     * ```php
     * $I->seeSoapResponseIncludes("<result>1</result>");
     * $I->seeSoapRequestIncludes(\Codeception\Utils\Soap::response()->result->val(1));
     *
     * $dom = new \DOMDocument();
     * $dom->load('template.xml');
     * $I->seeSoapRequestIncludes($dom);
     * ```
     *
     * @param XmlBuilder|DOMDocument|string $xml
     *
     * @throws ModuleException
     */
    public function seeSoapResponseIncludes($xml): void
    {
        $xml = $this->canonicalize($xml);
        $this->assertStringContainsString($xml, $this->getXmlResponse()->C14N(), 'found in XML Response');
    }


    /**
     * Checks XML response equals provided XML.
     * Comparison is done by canonicalizing both xml`s.
     *
     * Parameter can be passed either as XmlBuilder, DOMDocument, DOMNode, XML string, or array (if no attributes).
     *
     * @param string $xml
     *
     * @throws ModuleException
     */
    public function dontSeeSoapResponseEquals(string $xml): void
    {
        $xml = SoapUtils::toXml($xml);
        Assert::assertXmlStringNotEqualsXmlString($xml->C14N(), $this->getXmlResponse()->C14N());
    }


    /**
     * Checks XML response does not include provided XML.
     * Comparison is done by canonicalizing both xml`s.
     * Parameter can be passed either as XmlBuilder, DOMDocument, DOMNode, XML string, or array (if no attributes).
     *
     * @param XmlBuilder|DOMDocument|string $xml
     *
     * @throws ModuleException
     */
    public function dontSeeSoapResponseIncludes($xml): void
    {
        $xml = $this->canonicalize($xml);
        $this->assertStringNotContainsString($xml, $this->getXmlResponse()->C14N(), "found in XML Response");
    }

    /**
     * Checks XML response contains provided structure.
     * Response elements will be compared with XML provided.
     * Only nodeNames are checked to see elements match.
     *
     * Example:
     *
     * ```php
     * $I->seeSoapResponseContainsStructure("<query><name></name></query>");
     * ```
     *
     * Use this method to check XML of valid structure is returned.
     * This method does not use schema for validation.
     * This method does not require path from root to match the structure.
     *
     * @param string $xml
     *
     * @throws ModuleException
     */
    public function seeSoapResponseContainsStructure(string $xml): void
    {
        $xml = SoapUtils::toXml($xml);
        $this->debugSection("Structure", $xml->saveXML());
        $this->assertTrue($this->getXmlStructure()->matchXmlStructure($xml), "this structure is in response");
    }

    /**
     * Opposite to `seeSoapResponseContainsStructure`
     *
     * @param string $xml
     *
     * @throws ModuleException
     */
    public function dontSeeSoapResponseContainsStructure(string $xml): void
    {
        $xml = SoapUtils::toXml($xml);
        $this->debugSection("Structure", $xml->saveXML());
        $this->assertFalse($this->getXmlStructure()->matchXmlStructure($xml), "this structure is in response");
    }

    /**
     * Checks XML response with XPath locator
     *
     * ```php
     * $I->seeSoapResponseContainsXPath('//root/user[@id=1]');
     * ```
     *
     * @param string $xPath
     * @param array $namespaces
     * @param string$message
     *
     * @throws ModuleException
     */
    public function seeSoapResponseContainsXPath(string $xPath, $namespaces=[], $message=''): void
    {
        $nodeList = XmlAsserts::xmlQuery($xPath, $this->getXmlResponse(), $namespaces);
        $this->assertInstanceOf(\DOMNodeList::class, $nodeList, 'Invalid XPath query. '.$message);
        $this->assertGreaterThan(0, $nodeList->length, 'Soap Response does not contain XPath query. '.$message);
    }

    /**
     * Checks XML response doesn't contain XPath locator
     *
     * ```php
     * $I->dontSeeSoapResponseContainsXPath('//root/user[@id=1]');
     * ```
     *
     * @param string $xPath
     * @param array $namespaces
     * @param string$message
     *
     * @throws ModuleException
     */
    public function dontSeeSoapResponseContainsXPath(string $xPath, $namespaces, $message=''): void
    {
        $nodeList = XmlAsserts::xmlQuery($xPath, $this->getXmlResponse(), $namespaces);
        $this->assertInstanceOf(\DOMNodeList::class, $nodeList, 'Invalid XPath query. '.$message);
        $this->assertEquals(0, $nodeList->length, 'Soap Response does contain XPath query. '.$message);
    }

    /**
     * Checks response code from server.
     *
     * @param string $code
     */
    public function seeSoapResponseCodeIs(string $code): void
    {
        /** @noinspection PhpUndefinedMethodInspection */
        $this->assertEquals(
            $code,
            $this->client->getInternalResponse()->getStatus(),
            "soap response code matches expected"
        );
    }

    /**
     * Finds and returns text contents of element.
     * Element is matched by either CSS or XPath
     *
     * @param string $cssOrXPath
     *
     * @return string
     * @throws ModuleException
     * @version 1.1
     */
    public function grabTextContentFrom(string $cssOrXPath): string
    {
        $el = $this->getXmlStructure()->matchElement($cssOrXPath);
        return $el->textContent;
    }

    /**
     * Finds and returns attribute of element.
     * Element is matched by either CSS or XPath
     *
     * @param string $cssOrXPath
     * @param string $attribute
     *
     * @return string
     * @throws ModuleException
     * @version 1.1
     */
    public function grabAttributeFrom(string $cssOrXPath, string $attribute): string
    {
        $el = $this->getXmlStructure()->matchElement($cssOrXPath);
        $elHasAttribute = $el->hasAttribute($attribute);
        if (!$elHasAttribute) {
            $this->fail(sprintf('Attribute not found in element matched by \'%s\'', $cssOrXPath));
        }
        return $el->getAttribute($attribute);
    }

    /**
     * @return \DOMDocument
     * @throws \Codeception\Exception\ModuleException
     */
    public function grabSoapRequest() {
        return $this->xmlRequest;
    }

    /**
     * @return \DOMDocument
     * @throws \Codeception\Exception\ModuleException
     */
    public function grabSoapResponse() {
        return $this->xmlResponse;
    }

    /**
     * @throws \Codeception\Exception\ModuleException
     */
    public function seeResponseIsValidOnSchema($schema) {
        $this->assertTrue($this->response->schemaValidate($schema));
    }

    public function grabSoapResult() {
        return SoapClientDry::__responseValue($this->xmlResponse, $this->method, $this->wsdl, null);
    }

    protected function getSchema()
    {
        return $this->config['schema'];
    }

    /**
     * @param XmlBuilder|DOMDocument|string $xml
     * @return string
     */
    protected function canonicalize($xml): string
    {
        return SoapUtils::toXml($xml)->C14N();
    }

    protected function buildRequest(): DOMDocument
    {
        $soap_schema_url = $this->config['schema_url'];
        $xml = new DOMDocument();
        $root = $xml->createElementNS($soap_schema_url, 'SOAP-ENV:Envelope');
        $xml->appendChild($root);

        $header = $xml->createElementNS($soap_schema_url, 'SOAP-ENV:Header');
        $root->appendChild($header);

        $body = $xml->createElementNS($soap_schema_url, 'SOAP-ENV:Body');
        $root->appendChild($body);

        $this->xmlRequest = $xml;
        return $xml;
    }

    /**
     * @throws ModuleRequireException
     */
    protected function processRequest(string $action, string $body): void
    {
        $this->getClient()->request(
            'POST',
            $this->config['endpoint'],
            [],
            [],
            [
                'HTTP_Content-Type' => 'text/xml; charset=UTF-8',
                'HTTP_Content-Length' => strlen($body),
                'HTTP_SOAPAction' => $this->config['SOAPAction'] ?? $action
            ],
            $body
        );
    }

    /**
     * @return string|bool
     * @throws ModuleRequireException
     */
    protected function processInternalRequest(string $action, string $body)
    {
        ob_start();
        try {
            $this->getClient()->setServerParameter('HTTP_HOST', 'localhost');
            $this->processRequest($action, $body);
        }
            /** @noinspection PhpRedundantCatchClauseInspection */
        catch (ErrorException $e) {
            // Zend_Soap outputs warning as an exception
            if (strpos($e->getMessage(), 'Warning: Cannot modify header information') === false) {
                ob_end_clean();
                throw $e;
            }
        }
        $response = ob_get_contents();
        ob_end_clean();

        if($response=='' && $this->client) {
            /** @var Response $responseObject */
            $responseObject = $this->client->getResponse();
            if($responseObject instanceof Response)
                $response = $responseObject->getContent();
        }
        return $response;
    }

    /**
     * @throws ModuleRequireException
     */
    protected function processExternalRequest(string $action, string $body): string
    {
        $this->processRequest($action, $body);
        return $this->client->getInternalResponse()->getContent();
    }

    public function setSoapConfig($config) {
        foreach($config as $k=>$v) {
            $this->config[$k] = $v;
        }
    }

}
