--TEST--
Filtered writes must preserve acknowledged output or report an incomplete write
--FILE--
<?php
class LimitedWriteStream {
    public $context;
    public static $remaining;
    public static $data;

    public function stream_open($path, $mode, $options, &$opened_path) {
        return true;
    }

    public function stream_write($data) {
        $written = min(strlen($data), self::$remaining);
        self::$data .= substr($data, 0, $written);
        self::$remaining -= $written;
        return $written;
    }

    public function stream_flush() {
        return true;
    }
}

stream_wrapper_register('limitedwrite', LimitedWriteStream::class);

function assert_write_accounting(bool $condition, string $message): void {
    // Keep these assertions active even when zend.assertions disables assert().
    if (!$condition) {
        throw new AssertionError($message);
    }
}

function filtered_write($input, $capacity) {
    LimitedWriteStream::$remaining = $capacity;
    LimitedWriteStream::$data = '';
    $stream = fopen('limitedwrite://sink', 'w');
    stream_filter_append($stream, 'string.toupper', STREAM_FILTER_WRITE);
    $written = @fwrite($stream, $input);

    // A fix may retain unwritten output instead of reporting a short write.
    LimitedWriteStream::$remaining = strlen($input);
    @fflush($stream);
    @fclose($stream);
    return [$written, LimitedWriteStream::$data];
}

[$written, $output] = filtered_write('a', 1);
assert_write_accounting(
    $written === 1 && $output === 'A',
    'Ordinary filtered write must acknowledge and preserve its output'
);
echo "Ordinary filtered write succeeds\n";

foreach ([['a', 0], ['ab', 1]] as [$input, $capacity]) {
    [$written, $output] = filtered_write($input, $capacity);
    $accountedFor = $written === false || (
        is_int($written)
        && $written >= 0
        && $written <= strlen($input)
        && substr($output, 0, $written) === substr(strtoupper($input), 0, $written)
    );
    assert_write_accounting(
        $accountedFor,
        "Filtered write acknowledged discarded output at backing capacity $capacity"
    );
    echo "Backing capacity $capacity: acknowledged output is preserved or failure is reported\n";
}
?>
--EXPECT--
Ordinary filtered write succeeds
Backing capacity 0: acknowledged output is preserved or failure is reported
Backing capacity 1: acknowledged output is preserved or failure is reported
