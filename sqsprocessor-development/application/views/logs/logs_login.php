<html>
  <head>
  </head>
  <body>
    <div class="container-fluid">
      <div class="row no-gutter">
        <div class="col-md-8 col-lg-6">
          <div class="login d-flex align-items-center py-5">
            <div class="container">
              <div class="row">
                <div class="col-md-9 col-lg-8 mx-auto">
                  <h3 class="login-heading mb-4">Login for logs!</h3>

                  <?php if($this->session->flashdata('loginError')) { echo $this->session->flashdata('loginError'); } ?>

                  <form action="<?php echo site_url('logs/postLogin'); ?>" method="POST" id="logForm">
                    <div class="form-label-group">
                      <input type="email" name="email" id="inputEmail" class="form-control" placeholder="Email address" >
                      <label for="inputEmail">Email address</label>
                    </div> 
    
                    <div class="form-label-group">
                      <input type="password" name="password" id="inputPassword" class="form-control" placeholder="Password">
                      <label for="inputPassword">Password</label>
                    </div>
                    <button class="btn btn-lg btn-primary btn-block btn-login text-uppercase font-weight-bold mb-2" type="submit">Sign In</button>
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </body>
</html>