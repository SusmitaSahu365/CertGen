<?php
require __DIR__ . '/../src/bootstrap.php';

CertGen\Auth::require();
layout_top('Help', 'help');
?>
<div class="page-title"><div><h1>Help</h1><p>How templates, spreadsheets and placeholders fit together.</p></div></div>
<div class="row g-4">
  <div class="col-lg-8">
    <div class="panel mb-4"><div class="panel-head"><h2>The workflow</h2></div><div class="panel-body">
      <ol class="mb-0">
        <li class="mb-2"><strong>Template.</strong> Defines the fields (columns) and the printed layout. Start from a preset or build your own.</li>
        <li class="mb-2"><strong>Batch.</strong> Upload an Excel or CSV file. You match its columns to the template's fields, and rows with problems are skipped and reported.</li>
        <li class="mb-2"><strong>Review.</strong> Search, edit, add and delete records.</li>
        <li><strong>Generate.</strong> One combined PDF (a page per person) or a ZIP with a PDF per person.</li>
      </ol></div></div>

    <div class="panel mb-4"><div class="panel-head"><h2>Placeholders</h2></div><div class="panel-body">
      <p>Write <code class="ph">{{field_key}}</code> anywhere in a layout block and it is replaced by that record's value.</p>
      <table class="table"><thead><tr><th>Built-in</th><th>Meaning</th></tr></thead><tbody>
        <tr><td><code class="ph">{{serial_no}}</code></td><td>Counts up from the “First serial no.” you enter when generating</td></tr>
        <tr><td><code class="ph">{{record_no}}</code></td><td>The record's fixed number within its batch</td></tr>
        <tr><td><code class="ph">{{issue_date}}</code></td><td>The issue date chosen when generating</td></tr>
        <tr><td><code class="ph">{{today}}</code></td><td>Today's date</td></tr>
        <tr><td><code class="ph">{{org_name}}</code> <code class="ph">{{org_address}}</code> <code class="ph">{{org_contact}}</code> <code class="ph">{{place}}</code></td><td>From Settings</td></tr>
      </tbody></table></div></div>

    <div class="panel mb-4"><div class="panel-head"><h2>Filters</h2></div><div class="panel-body">
      <p>Add filters after a pipe. They can be chained: <code class="ph">{{full_name|trim|upper}}</code></p>
      <table class="table"><thead><tr><th>Filter</th><th>Example</th><th>Result</th></tr></thead><tbody>
        <tr><td><code class="ph">upper</code> <code class="ph">lower</code> <code class="ph">title</code></td><td><code class="ph">{{full_name|upper}}</code></td><td>ASHA PATIL</td></tr>
        <tr><td><code class="ph">date:FORMAT</code></td><td><code class="ph">{{date_of_birth|date:d F Y}}</code></td><td>12 June 2005 (PHP date format)</td></tr>
        <tr><td><code class="ph">date_words</code></td><td><code class="ph">{{date_of_birth|date_words}}</code></td><td>Twelfth of June Two Thousand and Five</td></tr>
        <tr><td><code class="ph">map:A=X,B=Y,*=Z</code></td><td><code class="ph">{{gender|map:M=He,F=She,*=They}}</code></td><td>Swaps one value for another (not case sensitive). <code class="ph">*</code> is the fallback.</td></tr>
        <tr><td><code class="ph">default:TEXT</code></td><td><code class="ph">{{remarks|default:-}}</code></td><td>Prints “-” when the value is empty</td></tr>
      </tbody></table>
      <p class="hint mb-0">Fields of type Date are shown in your Settings date format automatically.</p></div></div>

    <div class="panel"><div class="panel-head"><h2>Layout blocks</h2></div><div class="panel-body">
      <ul class="mb-0">
        <li><strong>Heading:</strong> large centred title.</li>
        <li><strong>Paragraph:</strong> free text with placeholders. Use <code class="ph">**double asterisks**</code> to make part of it bold.</li>
        <li><strong>Label : value:</strong> prints “Label: <strong>value</strong>” on one line.</li>
        <li><strong>Two columns:</strong> two short texts on one line (left and right).</li>
        <li><strong>Space</strong> and <strong>Line:</strong> vertical gap in mm, and a horizontal rule.</li>
      </ul></div></div>
  </div>
  <div class="col-lg-4">
    <div class="panel"><div class="panel-head"><h3>Good to know</h3></div><div class="panel-body hint">
      <p><strong>Dates in spreadsheets.</strong> Real Excel dates work. Typed dates are read day-first (31/12/2005).</p>
      <p><strong>Languages.</strong> PDFs use built-in fonts that cover Western European characters. Text in other scripts (such as Devanagari) prints as “?”. You are warned during import.</p>
      <p><strong>Printing.</strong> Pages are A4 portrait. Signature lines are left blank for hand signing.</p>
      <p class="mb-0"><strong>Official documents.</strong> Generated certificates are only as accurate as your data. Check them before issuing.</p>
    </div></div>
  </div>
</div>
<?php layout_bottom();
