<h2>🚨 Application Error Detected</h2>

<p><strong>Message:</strong> {{ $exception->getMessage() }}</p>
<p><strong>File:</strong> {{ $exception->getFile() }}</p>
<p><strong>Line:</strong> {{ $exception->getLine() }}</p>

<h4>URL:</h4>
<p>{{ request()->fullUrl() ?? 'CLI / Queue Job' }}</p>

<h4>Request Data:</h4>
<pre>{{ print_r($requestData, true) }}</pre>

<h4>Stack Trace:</h4>
<pre>{{ $exception->getTraceAsString() }}</pre>