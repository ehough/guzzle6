<?php
namespace Hough\Tests\Guzzle6;

use Hough\Guzzle6\Client;
use Hough\Guzzle6\Handler\MockHandler;
use Hough\Guzzle6\HandlerStack;
use Hough\Guzzle6\Middleware;
use Hough\Psr7\Request;
use Hough\Psr7\Response;
use Hough\Guzzle6\RedirectMiddleware;
use Psr\Http\Message\RequestInterface;

/**
 * @covers \Hough\Guzzle6\RedirectMiddleware
 */
class RedirectMiddlewareTest extends \PHPUnit_Framework_TestCase
{
    public function testIgnoresNonRedirects()
    {
        $response = new Response(200);
        $stack = new HandlerStack(new MockHandler(array($response)));
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com');
        $promise = $handler($request, array());
        $response = $promise->wait();
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testIgnoresWhenNoLocation()
    {
        $response = new Response(304);
        $stack = new HandlerStack(new MockHandler(array($response)));
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com');
        $promise = $handler($request, array());
        $response = $promise->wait();
        $this->assertEquals(304, $response->getStatusCode());
    }

    public function testRedirectsWithAbsoluteUri()
    {
        $mock = new MockHandler(array(
            new Response(302, array('Location' => 'http://test.com')),
            new Response(200)
        ));
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com?a=b');
        $promise = $handler($request, array(
            'allow_redirects' => array('max' => 2)
        ));
        $response = $promise->wait();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('http://test.com', $mock->getLastRequest()->getUri());
    }

    public function testRedirectsWithRelativeUri()
    {
        $mock = new MockHandler(array(
            new Response(302, array('Location' => '/foo')),
            new Response(200)
        ));
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com?a=b');
        $promise = $handler($request, array(
            'allow_redirects' => array('max' => 2)
        ));
        $response = $promise->wait();
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('http://example.com/foo', $mock->getLastRequest()->getUri());
    }

    /**
     * @expectedException \Hough\Guzzle6\Exception\TooManyRedirectsException
     * @expectedExceptionMessage Will not follow more than 3 redirects
     */
    public function testLimitsToMaxRedirects()
    {
        $mock = new MockHandler(array(
            new Response(301, array('Location' => 'http://test.com')),
            new Response(302, array('Location' => 'http://test.com')),
            new Response(303, array('Location' => 'http://test.com')),
            new Response(304, array('Location' => 'http://test.com'))
        ));
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com');
        $promise = $handler($request, array('allow_redirects' => array('max' => 3)));
        $promise->wait();
    }

    /**
     * @expectedException \Hough\Guzzle6\Exception\BadResponseException
     * @expectedExceptionMessage Redirect URI,
     */
    public function testEnsuresProtocolIsValid()
    {
        $mock = new MockHandler(array(
            new Response(301, array('Location' => 'ftp://test.com'))
        ));
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com');
        $handler($request, array('allow_redirects' => array('max' => 3)))->wait();
    }

    public function testAddsRefererHeader()
    {
        $mock = new MockHandler(array(
            new Response(302, array('Location' => 'http://test.com')),
            new Response(200)
        ));
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com?a=b');
        $promise = $handler($request, array(
            'allow_redirects' => array('max' => 2, 'referer' => true)
        ));
        $promise->wait();
        $this->assertEquals(
            'http://example.com?a=b',
            $mock->getLastRequest()->getHeaderLine('Referer')
        );
    }

    public function testAddsGuzzleRedirectHeader()
    {
        $mock = new MockHandler(array(
            new Response(302, array('Location' => 'http://example.com')),
            new Response(302, array('Location' => 'http://example.com/foo')),
            new Response(302, array('Location' => 'http://example.com/bar')),
            new Response(200)
        ));

        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com?a=b');
        $promise = $handler($request, array(
            'allow_redirects' => array('track_redirects' => true)
        ));
        $response = $promise->wait(true);
        $this->assertEquals(
            array(
                'http://example.com',
                'http://example.com/foo',
                'http://example.com/bar',
            ),
            $response->getHeader(RedirectMiddleware::HISTORY_HEADER)
        );
    }

    public function testDoesNotAddRefererWhenGoingFromHttpsToHttp()
    {
        $mock = new MockHandler(array(
            new Response(302, array('Location' => 'http://test.com')),
            new Response(200)
        ));
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'https://example.com?a=b');
        $promise = $handler($request, array(
            'allow_redirects' => array('max' => 2, 'referer' => true)
        ));
        $promise->wait();
        $this->assertFalse($mock->getLastRequest()->hasHeader('Referer'));
    }

    public function testInvokesOnRedirectForRedirects()
    {
        $mock = new MockHandler(array(
            new Response(302, array('Location' => 'http://test.com')),
            new Response(200)
        ));
        $stack = new HandlerStack($mock);
        $stack->push(Middleware::redirect());
        $handler = $stack->resolve();
        $request = new Request('GET', 'http://example.com?a=b');
        $call = false;
        $promise = $handler($request, array(
            'allow_redirects' => array(
                'max' => 2,
                'on_redirect' => function ($request, $response, $uri) use (&$call) {
                    $this->assertEquals(302, $response->getStatusCode());
                    $this->assertEquals('GET', $request->getMethod());
                    $this->assertEquals('http://test.com', (string) $uri);
                    $call = true;
                }
            )
        ));
        $promise->wait();
        $this->assertTrue($call);
    }

    public function testRemoveAuthorizationHeaderOnRedirect()
    {
        $mock = new MockHandler(array(
            new Response(302, array('Location' => 'http://test.com')),
            function (RequestInterface $request) {
                $this->assertFalse($request->hasHeader('Authorization'));
                return new Response(200);
            }
        ));
        $handler = HandlerStack::create($mock);
        $client = new Client(array('handler' => $handler));
        $client->get('http://example.com?a=b', array('auth' => array('testuser', 'testpass')));
    }

    public function testNotRemoveAuthorizationHeaderOnRedirect()
    {
        $mock = new MockHandler(array(
            new Response(302, array('Location' => 'http://example.com/2')),
            function (RequestInterface $request) {
                $this->assertTrue($request->hasHeader('Authorization'));
                return new Response(200);
            }
        ));
        $handler = HandlerStack::create($mock);
        $client = new Client(array('handler' => $handler));
        $client->get('http://example.com?a=b', array('auth' => array('testuser', 'testpass')));
    }
}
