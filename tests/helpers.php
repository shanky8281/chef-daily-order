<?php
/* Small test helpers: checks and an HTTP client that keeps its own login cookie. */
declare(strict_types=1);

$GLOBALS['failures'] = 0;
$GLOBALS['checks'] = 0;

function check(string $name, bool $ok, $detail = null): void
{
    $GLOBALS['checks']++;
    if ($ok) { echo "  ok    $name\n"; return; }
    $GLOBALS['failures']++;
    echo "  FAIL  $name" . ($detail === null ? '' : ' → ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
}

/** One browser: keeps cookies between requests. */
class Client
{
    private string $jar;

    public function __construct()
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'cdo-jar');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** @return array{0:int,1:array} status and decoded JSON body */
    public function call(string $method, string $path, ?array $body = null, bool $csrf = true): array
    {
        $ch = curl_init(BASE . '/api/' . $path);
        $headers = $csrf ? ['X-CDO: 1'] : [];
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 20,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $out = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, json_decode($out, true) ?? ['raw' => $out]];
    }

    public function get(string $p): array { return $this->call('GET', $p); }
    public function post(string $p, array $b = []): array { return $this->call('POST', $p, $b); }
    public function put(string $p, array $b = []): array { return $this->call('PUT', $p, $b); }
    public function delete(string $p, array $b = []): array { return $this->call('DELETE', $p, $b); }

    public static function login(string $username, string $password): self
    {
        $c = new self();
        [$s, $r] = $c->post('auth/login', ['username' => $username, 'password' => $password]);
        if ($s !== 200) throw new RuntimeException("login $username failed: " . json_encode($r));
        return $c;
    }
}

/** Last e-mail written to the test mail log. */
function last_mail(): ?array
{
    $lines = array_filter(explode("\n", (string)file_get_contents(MAIL_LOG)));
    return $lines ? json_decode(end($lines), true) : null;
}

function mail_count(): int
{
    return count(array_filter(explode("\n", (string)file_get_contents(MAIL_LOG))));
}
