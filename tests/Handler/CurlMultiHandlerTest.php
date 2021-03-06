<?php
namespace Hough\Tests\Handler;

use Hough\Guzzle\Handler\CurlMultiHandler;
use Hough\Psr7\Request;
use Hough\Psr7\Response;
use Hough\Guzzle\Test\Server;

class CurlMultiHandlerTest extends \PHPUnit_Framework_TestCase
{
    public function testSendsRequest()
    {
        Server::enqueue(array(new Response()));
        $a = new CurlMultiHandler();
        $request = new Request('GET', Server::$url);
        $response = call_user_func($a, $request, array())->wait();
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @expectedException \Hough\Guzzle\Exception\ConnectException
     * @expectedExceptionMessage cURL error
     */
    public function testCreatesExceptions()
    {
        $a = new CurlMultiHandler();
        call_user_func($a, new Request('GET', 'http://localhost:123'), array())->wait();
    }

    public function testCanSetSelectTimeout()
    {
        $a = new CurlMultiHandler(array('select_timeout' => 2));
        $this->assertEquals(2, $this->readAttribute($a, 'selectTimeout'));
    }

    public function testCanCancel()
    {
        Server::flush();
        $response = new Response(200);
        Server::enqueue(array_fill_keys(range(0, 10), $response));
        $a = new CurlMultiHandler();
        $responses = array();
        for ($i = 0; $i < 10; $i++) {
            $response = call_user_func($a, new Request('GET', Server::$url), array());
            $response->cancel();
            $responses[] = $response;
        }
    }

    public function testCannotCancelFinished()
    {
        Server::flush();
        Server::enqueue(array(new Response(200)));
        $a = new CurlMultiHandler();
        $response = call_user_func($a, new Request('GET', Server::$url), array());
        $response->wait();
        $response->cancel();
    }

    public function testDelaysConcurrently()
    {
        Server::flush();
        Server::enqueue(array(new Response()));
        $a = new CurlMultiHandler();
        $expected = microtime(true) + (100 / 1000);
        $response = call_user_func($a, new Request('GET', Server::$url), array('delay' => 100));
        $response->wait();
        $this->assertGreaterThanOrEqual($expected, microtime(true));
    }

    /**
     * @expectedException \BadMethodCallException
     */
    public function throwsWhenAccessingInvalidProperty()
    {
        $h = new CurlMultiHandler();
        $h->foo;
    }
}
