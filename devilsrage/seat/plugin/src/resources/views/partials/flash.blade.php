@if(session('success'))
  <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if($errors->any())
  <div class="alert alert-danger">
    @foreach($errors->all() as $error)
      <div>{{ $error }}</div>
    @endforeach
  </div>
@endif
<style>
  .dr-pill { display: inline-block; padding: .15rem .5rem; border-radius: 999px; font-size: .75rem; font-weight: 600; letter-spacing: .02em; }
  .dr-main { background: rgba(158, 42, 43, .15); color: #b03a3b; }
  .dr-second { background: rgba(45, 95, 143, .15); color: #2d6aa3; }
  .dr-third { background: rgba(140, 115, 40, .18); color: #8a7024; }
  .dr-custom { background: rgba(90, 70, 150, .14); color: #6a55b0; }
  .dr-none { background: rgba(120, 120, 120, .12); color: #777; }
</style>
