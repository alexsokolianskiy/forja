#include <stdint.h>

int32_t {{symbol}}(int64_t n, int32_t *forja_err)
{
    (void) forja_err;
    if (n < 2) {
        return 0;
    }
    for (int64_t d = 2; d <= n / d; d++) {
        if (n % d == 0) {
            return 0;
        }
    }
    return 1;
}
