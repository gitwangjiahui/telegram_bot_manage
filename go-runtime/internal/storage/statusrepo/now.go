package statusrepo

import "time"

func unixNow() int64 { return time.Now().Unix() }
