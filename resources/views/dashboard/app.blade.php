<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Trace · Vortech</title>
<style>{!! $style !!}</style>
</head>
<body>
<div id="app"></div>
<noscript><p style="padding:48px;font-family:system-ui;color:#c6c7c4">The Trace dashboard needs JavaScript.</p></noscript>
<script>window.__TRACE__ = {!! json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR) !!};</script>
<script>{!! $script !!}</script>
</body>
</html>
