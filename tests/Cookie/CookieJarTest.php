<?php
namespace Hough\Tests\CookieJar;

use Hough\Guzzle\Cookie\CookieJar;
use Hough\Guzzle\Cookie\SetCookie;
use Hough\Psr7\Request;
use Hough\Psr7\Response;

/**
 * @covers \Hough\Guzzle\Cookie\CookieJar
 */
class CookieJarTest extends \PHPUnit_Framework_TestCase
{
    /** @var CookieJar */
    private $jar;

    public function setUp()
    {
        $this->jar = new CookieJar();
    }

    protected function getTestCookies()
    {
        return array(
            new SetCookie(array('Name' => 'foo',  'Value' => 'bar', 'Domain' => 'foo.com', 'Path' => '/',    'Discard' => true)),
            new SetCookie(array('Name' => 'test', 'Value' => '123', 'Domain' => 'baz.com', 'Path' => '/foo', 'Expires' => 2)),
            new SetCookie(array('Name' => 'you',  'Value' => '123', 'Domain' => 'bar.com', 'Path' => '/boo', 'Expires' => time() + 1000))
        );
    }

    public function testCreatesFromArray()
    {
        $jar = CookieJar::fromArray(array(
            'foo' => 'bar',
            'baz' => 'bam'
        ), 'example.com');
        $this->assertCount(2, $jar);
    }

    /**
     * Provides test data for cookie cookieJar retrieval
     */
    public function getCookiesDataProvider()
    {
        return array(
            array(array('foo', 'baz', 'test', 'muppet', 'googoo'), '', '', '', false),
            array(array('foo', 'baz', 'muppet', 'googoo'), '', '', '', true),
            array(array('googoo'), 'www.example.com', '', '', false),
            array(array('muppet', 'googoo'), 'test.y.example.com', '', '', false),
            array(array('foo', 'baz'), 'example.com', '', '', false),
            array(array('muppet'), 'x.y.example.com', '/acme/', '', false),
            array(array('muppet'), 'x.y.example.com', '/acme/test/', '', false),
            array(array('googoo'), 'x.y.example.com', '/test/acme/test/', '', false),
            array(array('foo', 'baz'), 'example.com', '', '', false),
            array(array('baz'), 'example.com', '', 'baz', false),
        );
    }

    public function testStoresAndRetrievesCookies()
    {
        $cookies = $this->getTestCookies();
        foreach ($cookies as $cookie) {
            $this->assertTrue($this->jar->setCookie($cookie));
        }

        $this->assertEquals(3, count($this->jar));
        $this->assertEquals(3, count($this->jar->getIterator()));
        $this->assertEquals($cookies, $this->jar->getIterator()->getArrayCopy());
    }

    public function testRemovesTemporaryCookies()
    {
        $cookies = $this->getTestCookies();
        foreach ($this->getTestCookies() as $cookie) {
            $this->jar->setCookie($cookie);
        }
        $this->jar->clearSessionCookies();
        $this->assertEquals(
            array($cookies[1], $cookies[2]),
            $this->jar->getIterator()->getArrayCopy()
        );
    }

    public function testRemovesSelectively()
    {
        foreach ($this->getTestCookies() as $cookie) {
            $this->jar->setCookie($cookie);
        }

        // Remove foo.com cookies
        $this->jar->clear('foo.com');
        $this->assertEquals(2, count($this->jar));
        // Try again, removing no further cookies
        $this->jar->clear('foo.com');
        $this->assertEquals(2, count($this->jar));

        // Remove bar.com cookies with path of /boo
        $this->jar->clear('bar.com', '/boo');
        $this->assertEquals(1, count($this->jar));

        // Remove cookie by name
        $this->jar->clear(null, null, 'test');
        $this->assertEquals(0, count($this->jar));
    }

    public function testDoesNotAddIncompleteCookies()
    {
        $this->assertEquals(false, $this->jar->setCookie(new SetCookie()));
        $this->assertFalse($this->jar->setCookie(new SetCookie(array(
            'Name' => 'foo'
        ))));
        $this->assertFalse($this->jar->setCookie(new SetCookie(array(
            'Name' => false
        ))));
        $this->assertFalse($this->jar->setCookie(new SetCookie(array(
            'Name' => true
        ))));
        $this->assertFalse($this->jar->setCookie(new SetCookie(array(
            'Name'   => 'foo',
            'Domain' => 'foo.com'
        ))));
    }

    public function testDoesNotAddEmptyCookies()
    {
        $this->assertFalse($this->jar->setCookie(new SetCookie(array(
            'Name'   => '',
            'Domain' => 'foo.com',
            'Value'  => 0
        ))));
    }

    public function testDoesAddValidCookies()
    {
        $this->assertTrue($this->jar->setCookie(new SetCookie(array(
            'Name'   => '0',
            'Domain' => 'foo.com',
            'Value'  => 0
        ))));
        $this->assertTrue($this->jar->setCookie(new SetCookie(array(
            'Name'   => 'foo',
            'Domain' => 'foo.com',
            'Value'  => 0
        ))));
        $this->assertTrue($this->jar->setCookie(new SetCookie(array(
            'Name'   => 'foo',
            'Domain' => 'foo.com',
            'Value'  => 0.0
        ))));
        $this->assertTrue($this->jar->setCookie(new SetCookie(array(
            'Name'   => 'foo',
            'Domain' => 'foo.com',
            'Value'  => '0'
        ))));
    }

    public function testOverwritesCookiesThatAreOlderOrDiscardable()
    {
        $t = time() + 1000;
        $data = array(
            'Name'    => 'foo',
            'Value'   => 'bar',
            'Domain'  => '.example.com',
            'Path'    => '/',
            'Max-Age' => '86400',
            'Secure'  => true,
            'Discard' => true,
            'Expires' => $t
        );

        // Make sure that the discard cookie is overridden with the non-discard
        $this->assertTrue($this->jar->setCookie(new SetCookie($data)));
        $this->assertEquals(1, count($this->jar));

        $data['Discard'] = false;
        $this->assertTrue($this->jar->setCookie(new SetCookie($data)));
        $this->assertEquals(1, count($this->jar));

        $c = $this->jar->getIterator()->getArrayCopy();
        $this->assertEquals(false, $c[0]->getDiscard());

        // Make sure it doesn't duplicate the cookie
        $this->jar->setCookie(new SetCookie($data));
        $this->assertEquals(1, count($this->jar));

        // Make sure the more future-ful expiration date supersede the other
        $data['Expires'] = time() + 2000;
        $this->assertTrue($this->jar->setCookie(new SetCookie($data)));
        $this->assertEquals(1, count($this->jar));
        $c = $this->jar->getIterator()->getArrayCopy();
        $this->assertNotEquals($t, $c[0]->getExpires());
    }

    public function testOverwritesCookiesThatHaveChanged()
    {
        $t = time() + 1000;
        $data = array(
            'Name'    => 'foo',
            'Value'   => 'bar',
            'Domain'  => '.example.com',
            'Path'    => '/',
            'Max-Age' => '86400',
            'Secure'  => true,
            'Discard' => true,
            'Expires' => $t
        );

        // Make sure that the discard cookie is overridden with the non-discard
        $this->assertTrue($this->jar->setCookie(new SetCookie($data)));

        $data['Value'] = 'boo';
        $this->assertTrue($this->jar->setCookie(new SetCookie($data)));
        $this->assertEquals(1, count($this->jar));

        // Changing the value plus a parameter also must overwrite the existing one
        $data['Value'] = 'zoo';
        $data['Secure'] = false;
        $this->assertTrue($this->jar->setCookie(new SetCookie($data)));
        $this->assertEquals(1, count($this->jar));

        $c = $this->jar->getIterator()->getArrayCopy();
        $this->assertEquals('zoo', $c[0]->getValue());
    }

    public function testAddsCookiesFromResponseWithRequest()
    {
        $response = new Response(200, array(
            'Set-Cookie' => "fpc=d=.Hm.yh4.1XmJWjJfs4orLQzKzPImxklQoxXSHOZATHUSEFciRueW_7704iYUtsXNEXq0M92Px2glMdWypmJ7HIQl6XIUvrZimWjQ3vIdeuRbI.FNQMAfcxu_XN1zSx7l.AcPdKL6guHc2V7hIQFhnjRW0rxm2oHY1P4bGQxFNz7f.tHm12ZD3DbdMDiDy7TBXsuP4DM-&v=2; expires=Fri, 02-Mar-2019 02:17:40 GMT;"
        ));
        $request = new Request('GET', 'http://www.example.com');
        $this->jar->extractCookies($request, $response);
        $this->assertEquals(1, count($this->jar));
    }

    public function getMatchingCookiesDataProvider()
    {
        return array(
            array('https://example.com', 'foo=bar; baz=foobar'),
            array('http://example.com', ''),
            array('https://example.com:8912', 'foo=bar; baz=foobar'),
            array('https://foo.example.com', 'foo=bar; baz=foobar'),
            array('http://foo.example.com/test/acme/', 'googoo=gaga')
        );
    }

    /**
     * @dataProvider getMatchingCookiesDataProvider
     */
    public function testReturnsCookiesMatchingRequests($url, $cookies)
    {
        $bag = array(
            new SetCookie(array(
                'Name'    => 'foo',
                'Value'   => 'bar',
                'Domain'  => 'example.com',
                'Path'    => '/',
                'Max-Age' => '86400',
                'Secure'  => true
            )),
            new SetCookie(array(
                'Name'    => 'baz',
                'Value'   => 'foobar',
                'Domain'  => 'example.com',
                'Path'    => '/',
                'Max-Age' => '86400',
                'Secure'  => true
            )),
            new SetCookie(array(
                'Name'    => 'test',
                'Value'   => '123',
                'Domain'  => 'www.foobar.com',
                'Path'    => '/path/',
                'Discard' => true
            )),
            new SetCookie(array(
                'Name'    => 'muppet',
                'Value'   => 'cookie_monster',
                'Domain'  => '.y.example.com',
                'Path'    => '/acme/',
                'Expires' => time() + 86400
            )),
            new SetCookie(array(
                'Name'    => 'googoo',
                'Value'   => 'gaga',
                'Domain'  => '.example.com',
                'Path'    => '/test/acme/',
                'Max-Age' => 1500
            ))
        );

        foreach ($bag as $cookie) {
            $this->jar->setCookie($cookie);
        }

        $request = new Request('GET', $url);
        $request = $this->jar->withCookieHeader($request);
        $this->assertEquals($cookies, $request->getHeaderLine('Cookie'));
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Invalid cookie: Cookie name must not contain invalid characters: ASCII Control characters (0-31;127), space, tab and the following characters: ()<>@,;:\"/?={}
     */
    public function testThrowsExceptionWithStrictMode()
    {
        $a = new CookieJar(true);
        $a->setCookie(new SetCookie(array('Name' => "abc\n", 'Value' => 'foo', 'Domain' => 'bar')));
    }

    public function testDeletesCookiesByName()
    {
        $cookies = $this->getTestCookies();
        $cookies[] = new SetCookie(array(
            'Name' => 'other',
            'Value' => '123',
            'Domain' => 'bar.com',
            'Path' => '/boo',
            'Expires' => time() + 1000
        ));
        $jar = new CookieJar();
        foreach ($cookies as $cookie) {
            $jar->setCookie($cookie);
        }
        $this->assertCount(4, $jar);
        $jar->clear('bar.com', '/boo', 'other');
        $this->assertCount(3, $jar);
        $names = array_map(function (SetCookie $c) {
            return $c->getName();
        }, $jar->getIterator()->getArrayCopy());
        $this->assertEquals(array('foo', 'test', 'you'), $names);
    }

    public function testCanConvertToAndLoadFromArray()
    {
        $jar = new CookieJar(true);
        foreach ($this->getTestCookies() as $cookie) {
            $jar->setCookie($cookie);
        }
        $this->assertCount(3, $jar);
        $arr = $jar->toArray();
        $this->assertCount(3, $arr);
        $newCookieJar = new CookieJar(false, $arr);
        $this->assertCount(3, $newCookieJar);
        $this->assertSame($jar->toArray(), $newCookieJar->toArray());
    }
}
