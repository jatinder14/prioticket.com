<?php
// This file was auto-generated from sdk-root/src/data/elasticfilesystem/2015-02-01/endpoint-tests-1.json
return [ 'testCases' => [ [ 'documentation' => 'For region ap-south-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.ap-south-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'ap-south-1', ], ], [ 'documentation' => 'For region ap-south-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.ap-south-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'ap-south-1', ], ], [ 'documentation' => 'For region ap-south-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.ap-south-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'ap-south-1', ], ], [ 'documentation' => 'For region ap-south-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.ap-south-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'ap-south-1', ], ], [ 'documentation' => 'For region eu-south-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.eu-south-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'eu-south-1', ], ], [ 'documentation' => 'For region eu-south-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.eu-south-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'eu-south-1', ], ], [ 'documentation' => 'For region eu-south-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.eu-south-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'eu-south-1', ], ], [ 'documentation' => 'For region eu-south-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.eu-south-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'eu-south-1', ], ], [ 'documentation' => 'For region eu-south-2 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.eu-south-2.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'eu-south-2', ], ], [ 'documentation' => 'For region eu-south-2 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.eu-south-2.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'eu-south-2', ], ], [ 'documentation' => 'For region eu-south-2 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.eu-south-2.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'eu-south-2', ], ], [ 'documentation' => 'For region eu-south-2 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.eu-south-2.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'eu-south-2', ], ], [ 'documentation' => 'For region us-gov-east-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.us-gov-east-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'us-gov-east-1', ], ], [ 'documentation' => 'For region us-gov-east-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.us-gov-east-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'us-gov-east-1', ], ], [ 'documentation' => 'For region us-gov-east-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.us-gov-east-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'us-gov-east-1', ], ], [ 'documentation' => 'For region us-gov-east-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.us-gov-east-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'us-gov-east-1', ], ], [ 'documentation' => 'For region me-central-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.me-central-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'me-central-1', ], ], [ 'documentation' => 'For region me-central-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.me-central-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'me-central-1', ], ], [ 'documentation' => 'For region me-central-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.me-central-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'me-central-1', ], ], [ 'documentation' => 'For region me-central-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.me-central-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'me-central-1', ], ], [ 'documentation' => 'For region ca-central-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.ca-central-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'ca-central-1', ], ], [ 'documentation' => 'For region ca-central-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.ca-central-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'ca-central-1', ], ], [ 'documentation' => 'For region ca-central-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.ca-central-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'ca-central-1', ], ], [ 'documentation' => 'For region ca-central-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.ca-central-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'ca-central-1', ], ], [ 'documentation' => 'For region eu-central-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.eu-central-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'eu-central-1', ], ], [ 'documentation' => 'For region eu-central-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.eu-central-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'eu-central-1', ], ], [ 'documentation' => 'For region eu-central-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.eu-central-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'eu-central-1', ], ], [ 'documentation' => 'For region eu-central-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.eu-central-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'eu-central-1', ], ], [ 'documentation' => 'For region us-iso-west-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'error' => 'FIPS and DualStack are enabled, but this partition does not support one or both', ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'us-iso-west-1', ], ], [ 'documentation' => 'For region us-iso-west-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.us-iso-west-1.c2s.ic.gov', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'us-iso-west-1', ], ], [ 'documentation' => 'For region us-iso-west-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'error' => 'DualStack is enabled but this partition does not support DualStack', ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'us-iso-west-1', ], ], [ 'documentation' => 'For region us-iso-west-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.us-iso-west-1.c2s.ic.gov', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'us-iso-west-1', ], ], [ 'documentation' => 'For region eu-central-2 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.eu-central-2.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'eu-central-2', ], ], [ 'documentation' => 'For region eu-central-2 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.eu-central-2.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'eu-central-2', ], ], [ 'documentation' => 'For region eu-central-2 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.eu-central-2.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'eu-central-2', ], ], [ 'documentation' => 'For region eu-central-2 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.eu-central-2.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'eu-central-2', ], ], [ 'documentation' => 'For region us-west-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.us-west-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'us-west-1', ], ], [ 'documentation' => 'For region us-west-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.us-west-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'us-west-1', ], ], [ 'documentation' => 'For region us-west-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.us-west-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'us-west-1', ], ], [ 'documentation' => 'For region us-west-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.us-west-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'us-west-1', ], ], [ 'documentation' => 'For region us-west-2 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.us-west-2.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'us-west-2', ], ], [ 'documentation' => 'For region us-west-2 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.us-west-2.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'us-west-2', ], ], [ 'documentation' => 'For region us-west-2 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.us-west-2.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'us-west-2', ], ], [ 'documentation' => 'For region us-west-2 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.us-west-2.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'us-west-2', ], ], [ 'documentation' => 'For region af-south-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.af-south-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'af-south-1', ], ], [ 'documentation' => 'For region af-south-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.af-south-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'af-south-1', ], ], [ 'documentation' => 'For region af-south-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.af-south-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'af-south-1', ], ], [ 'documentation' => 'For region af-south-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.af-south-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'af-south-1', ], ], [ 'documentation' => 'For region eu-north-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.eu-north-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'eu-north-1', ], ], [ 'documentation' => 'For region eu-north-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.eu-north-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'eu-north-1', ], ], [ 'documentation' => 'For region eu-north-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.eu-north-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'eu-north-1', ], ], [ 'documentation' => 'For region eu-north-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.eu-north-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'eu-north-1', ], ], [ 'documentation' => 'For region eu-west-3 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.eu-west-3.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'eu-west-3', ], ], [ 'documentation' => 'For region eu-west-3 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.eu-west-3.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'eu-west-3', ], ], [ 'documentation' => 'For region eu-west-3 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.eu-west-3.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'eu-west-3', ], ], [ 'documentation' => 'For region eu-west-3 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.eu-west-3.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'eu-west-3', ], ], [ 'documentation' => 'For region eu-west-2 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.eu-west-2.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'eu-west-2', ], ], [ 'documentation' => 'For region eu-west-2 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.eu-west-2.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'eu-west-2', ], ], [ 'documentation' => 'For region eu-west-2 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.eu-west-2.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'eu-west-2', ], ], [ 'documentation' => 'For region eu-west-2 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.eu-west-2.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'eu-west-2', ], ], [ 'documentation' => 'For region eu-west-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.eu-west-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'eu-west-1', ], ], [ 'documentation' => 'For region eu-west-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.eu-west-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'eu-west-1', ], ], [ 'documentation' => 'For region eu-west-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.eu-west-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'eu-west-1', ], ], [ 'documentation' => 'For region eu-west-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.eu-west-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'eu-west-1', ], ], [ 'documentation' => 'For region ap-northeast-3 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.ap-northeast-3.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'ap-northeast-3', ], ], [ 'documentation' => 'For region ap-northeast-3 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.ap-northeast-3.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'ap-northeast-3', ], ], [ 'documentation' => 'For region ap-northeast-3 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.ap-northeast-3.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'ap-northeast-3', ], ], [ 'documentation' => 'For region ap-northeast-3 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.ap-northeast-3.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'ap-northeast-3', ], ], [ 'documentation' => 'For region ap-northeast-2 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.ap-northeast-2.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'ap-northeast-2', ], ], [ 'documentation' => 'For region ap-northeast-2 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.ap-northeast-2.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'ap-northeast-2', ], ], [ 'documentation' => 'For region ap-northeast-2 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.ap-northeast-2.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'ap-northeast-2', ], ], [ 'documentation' => 'For region ap-northeast-2 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.ap-northeast-2.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'ap-northeast-2', ], ], [ 'documentation' => 'For region ap-northeast-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.ap-northeast-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'ap-northeast-1', ], ], [ 'documentation' => 'For region ap-northeast-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.ap-northeast-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'ap-northeast-1', ], ], [ 'documentation' => 'For region ap-northeast-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.ap-northeast-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'ap-northeast-1', ], ], [ 'documentation' => 'For region ap-northeast-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.ap-northeast-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'ap-northeast-1', ], ], [ 'documentation' => 'For region me-south-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.me-south-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'me-south-1', ], ], [ 'documentation' => 'For region me-south-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.me-south-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'me-south-1', ], ], [ 'documentation' => 'For region me-south-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.me-south-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'me-south-1', ], ], [ 'documentation' => 'For region me-south-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.me-south-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'me-south-1', ], ], [ 'documentation' => 'For region sa-east-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.sa-east-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'sa-east-1', ], ], [ 'documentation' => 'For region sa-east-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.sa-east-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'sa-east-1', ], ], [ 'documentation' => 'For region sa-east-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.sa-east-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'sa-east-1', ], ], [ 'documentation' => 'For region sa-east-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.sa-east-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'sa-east-1', ], ], [ 'documentation' => 'For region ap-east-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.ap-east-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'ap-east-1', ], ], [ 'documentation' => 'For region ap-east-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.ap-east-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'ap-east-1', ], ], [ 'documentation' => 'For region ap-east-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.ap-east-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'ap-east-1', ], ], [ 'documentation' => 'For region ap-east-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.ap-east-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'ap-east-1', ], ], [ 'documentation' => 'For region cn-north-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.cn-north-1.api.amazonwebservices.com.cn', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'cn-north-1', ], ], [ 'documentation' => 'For region cn-north-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.cn-north-1.amazonaws.com.cn', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'cn-north-1', ], ], [ 'documentation' => 'For region cn-north-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.cn-north-1.api.amazonwebservices.com.cn', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'cn-north-1', ], ], [ 'documentation' => 'For region cn-north-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.cn-north-1.amazonaws.com.cn', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'cn-north-1', ], ], [ 'documentation' => 'For region us-gov-west-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.us-gov-west-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'us-gov-west-1', ], ], [ 'documentation' => 'For region us-gov-west-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.us-gov-west-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'us-gov-west-1', ], ], [ 'documentation' => 'For region us-gov-west-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.us-gov-west-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'us-gov-west-1', ], ], [ 'documentation' => 'For region us-gov-west-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.us-gov-west-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'us-gov-west-1', ], ], [ 'documentation' => 'For region ap-southeast-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.ap-southeast-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'ap-southeast-1', ], ], [ 'documentation' => 'For region ap-southeast-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.ap-southeast-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'ap-southeast-1', ], ], [ 'documentation' => 'For region ap-southeast-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.ap-southeast-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'ap-southeast-1', ], ], [ 'documentation' => 'For region ap-southeast-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.ap-southeast-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'ap-southeast-1', ], ], [ 'documentation' => 'For region ap-southeast-2 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.ap-southeast-2.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'ap-southeast-2', ], ], [ 'documentation' => 'For region ap-southeast-2 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.ap-southeast-2.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'ap-southeast-2', ], ], [ 'documentation' => 'For region ap-southeast-2 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.ap-southeast-2.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'ap-southeast-2', ], ], [ 'documentation' => 'For region ap-southeast-2 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.ap-southeast-2.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'ap-southeast-2', ], ], [ 'documentation' => 'For region us-iso-east-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'error' => 'FIPS and DualStack are enabled, but this partition does not support one or both', ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'us-iso-east-1', ], ], [ 'documentation' => 'For region us-iso-east-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.us-iso-east-1.c2s.ic.gov', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'us-iso-east-1', ], ], [ 'documentation' => 'For region us-iso-east-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'error' => 'DualStack is enabled but this partition does not support DualStack', ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'us-iso-east-1', ], ], [ 'documentation' => 'For region us-iso-east-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.us-iso-east-1.c2s.ic.gov', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'us-iso-east-1', ], ], [ 'documentation' => 'For region ap-southeast-3 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.ap-southeast-3.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'ap-southeast-3', ], ], [ 'documentation' => 'For region ap-southeast-3 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.ap-southeast-3.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'ap-southeast-3', ], ], [ 'documentation' => 'For region ap-southeast-3 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.ap-southeast-3.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'ap-southeast-3', ], ], [ 'documentation' => 'For region ap-southeast-3 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.ap-southeast-3.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'ap-southeast-3', ], ], [ 'documentation' => 'For region us-east-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.us-east-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'us-east-1', ], ], [ 'documentation' => 'For region us-east-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.us-east-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'us-east-1', ], ], [ 'documentation' => 'For region us-east-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.us-east-1.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'us-east-1', ], ], [ 'documentation' => 'For region us-east-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.us-east-1.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'us-east-1', ], ], [ 'documentation' => 'For region us-east-2 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.us-east-2.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'us-east-2', ], ], [ 'documentation' => 'For region us-east-2 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.us-east-2.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'us-east-2', ], ], [ 'documentation' => 'For region us-east-2 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.us-east-2.api.aws', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'us-east-2', ], ], [ 'documentation' => 'For region us-east-2 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.us-east-2.amazonaws.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'us-east-2', ], ], [ 'documentation' => 'For region cn-northwest-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.cn-northwest-1.api.amazonwebservices.com.cn', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'cn-northwest-1', ], ], [ 'documentation' => 'For region cn-northwest-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.cn-northwest-1.amazonaws.com.cn', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'cn-northwest-1', ], ], [ 'documentation' => 'For region cn-northwest-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.cn-northwest-1.api.amazonwebservices.com.cn', ], ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'cn-northwest-1', ], ], [ 'documentation' => 'For region cn-northwest-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.cn-northwest-1.amazonaws.com.cn', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'cn-northwest-1', ], ], [ 'documentation' => 'For region us-isob-east-1 with FIPS enabled and DualStack enabled', 'expect' => [ 'error' => 'FIPS and DualStack are enabled, but this partition does not support one or both', ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => true, 'Region' => 'us-isob-east-1', ], ], [ 'documentation' => 'For region us-isob-east-1 with FIPS enabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem-fips.us-isob-east-1.sc2s.sgov.gov', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'us-isob-east-1', ], ], [ 'documentation' => 'For region us-isob-east-1 with FIPS disabled and DualStack enabled', 'expect' => [ 'error' => 'DualStack is enabled but this partition does not support DualStack', ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'us-isob-east-1', ], ], [ 'documentation' => 'For region us-isob-east-1 with FIPS disabled and DualStack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://elasticfilesystem.us-isob-east-1.sc2s.sgov.gov', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'us-isob-east-1', ], ], [ 'documentation' => 'For custom endpoint with fips disabled and dualstack disabled', 'expect' => [ 'endpoint' => [ 'url' => 'https://example.com', ], ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => false, 'Region' => 'us-east-1', 'Endpoint' => 'https://example.com', ], ], [ 'documentation' => 'For custom endpoint with fips enabled and dualstack disabled', 'expect' => [ 'error' => 'Invalid Configuration: FIPS and custom endpoint are not supported', ], 'params' => [ 'UseDualStack' => false, 'UseFIPS' => true, 'Region' => 'us-east-1', 'Endpoint' => 'https://example.com', ], ], [ 'documentation' => 'For custom endpoint with fips disabled and dualstack enabled', 'expect' => [ 'error' => 'Invalid Configuration: Dualstack and custom endpoint are not supported', ], 'params' => [ 'UseDualStack' => true, 'UseFIPS' => false, 'Region' => 'us-east-1', 'Endpoint' => 'https://example.com', ], ], ], 'version' => '1.0',];
