use strict;
use warnings;
use Test::More;
use Cwd 'abs_path';

my $script = abs_path('withdraw_customer_atomic.php');
my $output = qx(php $script 2>&1);

is($?, 0, 'withdraw helper regression script passes') or diag($output);

done_testing();
