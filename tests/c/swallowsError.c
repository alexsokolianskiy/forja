#include <stdint.h>

/* Deliberately returns a value where PHP throws: stands in for a translation that ignores error cases. */
int64_t {{symbol}}(int64_t total, int64_t count, int32_t *forja_err)
{
    (void) forja_err;
    return count == 0 ? 0 : total / count;
}
